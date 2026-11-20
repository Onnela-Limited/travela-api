<?php
namespace Tests\Feature;

use App\Mail\PartnerLoginCodeMail;
use App\Models\{PartnerApplication,PartnerTeamMember};
use App\Support\PartnerIdentity;
use Illuminate\Support\Facades\{DB,Mail,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAutomaticSignInTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        config(['cache.default'=>'array']); Mail::fake(); Http::fake(); Http::preventStrayRequests();
        foreach(['2026_10_25_000001_create_partner_applications_table','2026_10_26_000001_add_partner_authentication','2026_10_26_000002_add_partner_application_reviews','2026_10_29_000001_create_partner_referrals','2026_11_01_000001_create_partner_teams','2026_11_20_000001_create_partner_login_choices'] as $migration) (require database_path('migrations/'.$migration.'.php'))->up();
    }
    private function application(string $email='owner@example.test',string $type='hotel',string $status='approved'): PartnerApplication {
        return PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'status'=>$status,'email_hash'=>PartnerIdentity::emailHash($email),
            'application_payload'=>['type'=>$type,'language'=>'en','profile'=>['displayName'=>'Test Hotel','legalName'=>'PRIVATE OWNER','country'=>'Tanzania','city'=>'Dar','phone'=>'+255712345678','email'=>$email],'identity'=>['id'=>'PRIVATE'],'payout'=>['account'=>'PRIVATE']], 'documents'=>[], 'submitted_at'=>now()]);
    }
    private function member(PartnerApplication $app,string $email='desk@example.test',string $role='front_desk',string $status='active'): PartnerTeamMember {
        $hash=PartnerIdentity::emailHash($email);
        return PartnerTeamMember::create(['partner_application_id'=>$app->id,'request_key'=>(string)Str::uuid(),'email_hash'=>$hash,'active_email_hash'=>$hash,'details'=>['name'=>'Desk','email'=>$email],'role'=>$role,'status'=>$status,'version'=>1,'invitation_hash'=>$status==='invited'?hash('sha256',Str::random(64)):null,'invitation_expires_at'=>now()->addDays(7)]);
    }
    private function code(string $email): array {
        $response=$this->postJson('/api/partner-auth/code',['email'=>$email,'mode'=>'auto','language'=>'en'])->assertStatus(202);
        $this->assertSame(['challenge_id','expires_in','retry_after'],array_keys($response->json()));
        return ['challenge_id'=>$response->json('challenge_id'),'code'=>Mail::sent(PartnerLoginCodeMail::class)->last()?->code ?? '000000'];
    }
    private function verify(array $code) { return $this->postJson('/api/partner-auth/auto-verify',$code); }
    private function select(array $code,array $choices,string $id,?string $token=null) {
        return $this->postJson('/api/partner-auth/select',['challenge_id'=>$code['challenge_id'],'selection_token'=>$token??$choices['selection_token'],'account_id'=>$id]);
    }
    private function dual(): array {
        $owner=$this->application('shared@example.test','content_creator','under_review');
        $business=$this->application(); $member=$this->member($business,'shared@example.test');
        $code=$this->code('shared@example.test');
        $choices=$this->verify($code)->assertOk()->assertJsonCount(2,'accounts')->assertJsonMissingPath('session_token')->assertJsonMissingPath('partner')->json();
        return [$owner,$business,$member,$code,$choices];
    }
    public function test_owner_is_identified_without_role_selection_and_otp_is_single_use(): void {
        $app=$this->application('owner@example.test','content_creator','under_review');
        $code=$this->code(' OWNER@example.test ');
        $response=$this->verify($code)->assertOk()->assertJsonPath('partner.role','owner')->assertJsonPath('partner.reference',$app->reference);
        $this->assertSame(hash('sha256',$response->json('session_token')),DB::table('partner_sessions')->sole()->token_hash);
        $this->verify($code)->assertUnprocessable();
    }
    public function test_front_desk_and_manager_are_automatically_identified_without_owner_data(): void {
        $app=$this->application();
        foreach(['front_desk','manager'] as $role) {
            $email=$role.'@example.test'; $this->member($app,$email,$role);
            $response=$this->verify($this->code($email))->assertOk()->assertJsonPath('partner.role',$role)->assertJsonPath('partner.profile.legalName','')->assertJsonMissingPath('partner.payout');
            $this->withToken($response->json('session_token'))->getJson('/api/partner-auth/team')->assertForbidden();
        }
    }
    public function test_unknown_or_unaccepted_invited_emails_do_not_send_codes_or_reveal_accounts(): void {
        $this->member($this->application(),'invited@example.test','front_desk','invited');
        foreach(['unknown@example.test','invited@example.test'] as $email) {
            $code=$this->code($email); $this->verify($code)->assertUnprocessable();
        }
        Mail::assertNothingSent(); $this->assertSame(0,DB::table('partner_sessions')->count());
    }
    public function test_account_choice_is_only_available_after_otp_and_cannot_be_forged_or_replayed(): void {
        [$owner,$business,$member,$code,$choices]=$this->dual();
        $this->assertSame(0,DB::table('partner_sessions')->count());
        $this->assertStringNotContainsString('PRIVATE',json_encode($choices));
        $this->assertStringNotContainsString('owner:'.$owner->id,DB::table('partner_login_choices')->sole()->accounts);
        $this->assertSame(hash('sha256',$choices['selection_token']),DB::table('partner_login_choices')->sole()->selection_token_hash);
        $this->select($code,$choices,'owner:'.$business->id)->assertUnprocessable();
        $this->select($code,$choices,'staff:'.$member->id,Str::random(64))->assertUnprocessable();
        $this->select($code,$choices,'staff:'.$member->id)->assertOk()->assertJsonPath('partner.role','front_desk')->assertJsonPath('partner.reference',$business->reference);
        $this->select($code,$choices,'owner:'.$owner->id)->assertUnprocessable();
        $this->verify($code)->assertUnprocessable();
        $this->assertSame(1,DB::table('partner_sessions')->count());
    }
    public function test_dual_email_can_choose_its_owner_account(): void {
        [$owner,$business,$member,$code,$choices]=$this->dual();
        $this->select($code,$choices,'owner:'.$owner->id)->assertOk()->assertJsonPath('partner.role','owner')->assertJsonPath('partner.reference',$owner->reference);
    }
    public function test_role_changes_and_revocations_block_old_team_choices(): void {
        foreach(['role','revoked','suspended'] as $change) {
            [$owner,$business,$member,$code,$choices]=$this->dual();
            if($change==='role') $member->update(['role'=>'manager','version'=>2]);
            elseif($change==='revoked') $member->update(['status'=>'revoked','active_email_hash'=>null]);
            else $business->update(['status'=>'suspended']);
            $this->select($code,$choices,'staff:'.$member->id)->assertUnprocessable();
            $this->select($code,$choices,'owner:'.$owner->id)->assertOk()->assertJsonPath('partner.role','owner');
            DB::table('partner_login_challenges')->delete(); DB::table('partner_sessions')->delete(); DB::table('partner_login_choices')->delete();
            PartnerTeamMember::query()->delete(); DB::table('partner_referrals')->delete(); PartnerApplication::query()->delete();
        }
    }
    public function test_expired_selection_or_resending_a_code_invalidates_old_choice(): void {
        [$owner,$business,$member,$code,$choices]=$this->dual();
        $this->travel(301)->seconds();
        $this->select($code,$choices,'owner:'.$owner->id)->assertUnprocessable();
        $new=$this->code('shared@example.test');
        $this->select($code,$choices,'owner:'.$owner->id)->assertUnprocessable();
        $this->verify($new)->assertOk()->assertJsonCount(2,'accounts');
    }
    public function test_code_attempt_limits_expiry_and_cooldown_still_apply(): void {
        $this->application(); $code=$this->code('owner@example.test');
        $this->postJson('/api/partner-auth/code',['email'=>'owner@example.test','mode'=>'auto'])->assertStatus(429);
        $wrong=$code; $wrong['code']=$code['code']==='000000'?'111111':'000000';
        for($i=0;$i<5;$i++) $this->verify($wrong)->assertUnprocessable();
        $this->verify($code)->assertUnprocessable();
        $this->travel(61)->seconds(); $code=$this->code('owner@example.test');
        $this->travel(601)->seconds(); $this->verify($code)->assertUnprocessable();
    }
    public function test_staff_revoked_between_request_and_verification_cannot_sign_in(): void {
        $member=$this->member($this->application()); $code=$this->code('desk@example.test');
        $member->update(['status'=>'revoked','active_email_hash'=>null,'version'=>2]);
        $this->verify($code)->assertUnprocessable(); $this->assertSame(0,DB::table('partner_sessions')->count());
    }
}
