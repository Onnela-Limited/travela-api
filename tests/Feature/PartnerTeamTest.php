<?php

namespace Tests\Feature;

use App\Mail\{PartnerLoginCodeMail, PartnerTeamInvitationMail};
use App\Models\{PartnerApplication, PartnerTeamMember};
use App\Support\PartnerIdentity;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerTeamTest extends TestCase
{
    private PartnerApplication $business;
    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','partners.portal_url'=>'http://localhost:3001']);
        DB::purge('sqlite');
        (require database_path('migrations/2025_10_05_114523_create_personal_access_tokens_table.php'))->up();
        foreach (['2026_10_25_000001_create_partner_applications_table','2026_10_26_000001_add_partner_authentication','2026_10_26_000002_add_partner_application_reviews','2026_10_29_000001_create_partner_referrals','2026_11_01_000001_create_partner_teams'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        Mail::fake();
        $this->business=PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'status'=>'approved','email_hash'=>PartnerIdentity::emailHash('owner@example.test'),
            'application_payload'=>['type'=>'hotel','language'=>'en','profile'=>['displayName'=>'Test Hotel','legalName'=>'PRIVATE OWNER','email'=>'owner@example.test'],'identity'=>['id'=>'PRIVATE'],'payout'=>['account'=>'PRIVATE']], 'documents'=>[], 'submitted_at'=>now()]);
        $this->owner=$this->staffSession();
    }

    private function staffSession(?PartnerTeamMember $member=null): string
    {
        $token=Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id'=>$this->business->id,'partner_team_member_id'=>$member?->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHours(12),'created_at'=>now(),'updated_at'=>now()]);
        return $token;
    }
    private function invite(string $role='front_desk',?string $key=null)
    {
        return $this->withToken($this->owner)->postJson('/api/partner-auth/team',['request_key'=>$key??(string)Str::uuid(),'name'=>'Staff','email'=>'staff@example.test','role'=>$role]);
    }
    private function member(): PartnerTeamMember { return PartnerTeamMember::firstOrFail(); }
    private function invitationToken(): string { return explode('#',Mail::sent(PartnerTeamInvitationMail::class)->last()->invitationUrl)[1]; }
    private function challenge(?string $token=null): array
    {
        $response=$this->postJson('/api/partner-auth/code',['email'=>'staff@example.test','mode'=>'staff','invitation_token'=>$token])->assertStatus(202);
        return ['challenge_id'=>$response->json('challenge_id'),'code'=>Mail::sent(PartnerLoginCodeMail::class)->last()->code];
    }
    private function accept(string $role='front_desk'): string
    {
        $this->invite($role)->assertOk();
        return $this->postJson('/api/partner-auth/verify',$this->challenge($this->invitationToken()))->assertOk()->assertJsonPath('partner.role',$role)->json('session_token');
    }
    private function change(string $action,int $version=0,?string $role=null)
    {
        return $this->withToken($this->owner)->postJson('/api/partner-auth/team/'.$this->member()->id,['action'=>$action,'expected_version'=>$version,'role'=>$role]);
    }

    public function test_invitation_is_hashed_encrypted_idempotent_and_public_lookup_is_redacted(): void
    {
        $key=(string)Str::uuid();$this->invite(key:$key)->assertOk()->assertJsonPath('member.status','invited')->assertJsonPath('member.deliveryStatus','sent');
        $this->invite(key:$key)->assertOk();Mail::assertSent(PartnerTeamInvitationMail::class,1);
        $token=$this->invitationToken();$record=DB::table('partner_team_members')->sole();
        $this->assertSame(hash('sha256',$token),$record->invitation_hash);
        $this->assertStringNotContainsString('staff@example.test',$record->details);
        $this->postJson('/api/public/partner-team/invitation',['token'=>$token])->assertOk()->assertJsonPath('invitation.business','Test Hotel')->assertJsonMissingPath('invitation.email')->assertJsonMissingPath('invitation.token');
        $this->assertSame(1,DB::table('partner_team_events')->count());
    }
    public function test_acceptance_verifies_email_consumes_invitation_and_redacts_owner_data(): void
    {
        $token=$this->accept();
        $this->assertSame('active',$this->member()->status);$this->assertNull($this->member()->invitation_hash);
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertOk()->assertJsonPath('partner.viewer.email','staff@example.test')->assertJsonPath('partner.profile.legalName','')->assertJsonPath('partner.referralCode',null)->assertJsonMissingPath('partner.payout');
        $this->assertSame(2,DB::table('partner_team_events')->count());
    }
    public function test_front_desk_cannot_access_financial_bulk_team_or_admin_routes(): void
    {
        $token=$this->accept();
        foreach (['dashboard','sales','payouts','referral','referral/qr','bulk','bulk/catalog','team'] as $path) $this->withToken($token)->getJson('/api/partner-auth/'.$path)->assertForbidden();
        foreach (['payouts','bulk','bulk/1/checkout','bulk/1/refresh-payment','team','team/1'] as $path) $this->withToken($token)->postJson('/api/partner-auth/'.$path,[])->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/partner-bulk')->assertUnauthorized();
    }
    public function test_manager_has_referral_but_cannot_manage_team_or_payouts(): void
    {
        $token=$this->accept('manager');
        $this->withToken($token)->getJson('/api/partner-auth/referral')->assertOk();
        foreach (['team','payouts'] as $path) {
            $this->withToken($token)->getJson('/api/partner-auth/'.$path)->assertForbidden();
            $this->withToken($token)->postJson('/api/partner-auth/'.$path,[])->assertForbidden();
        }
    }
    public function test_role_change_invalidates_sessions_and_outstanding_codes(): void
    {
        $token=$this->accept();$this->travel(61)->seconds();$code=$this->challenge();
        $this->change('role',1,'manager')->assertOk()->assertJsonPath('member.version',2);
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertUnauthorized();
        $this->postJson('/api/partner-auth/verify',$code)->assertUnprocessable();
        $this->withToken($this->owner)->getJson('/api/partner-auth/team')->assertOk();
        $this->change('role',1,'front_desk')->assertConflict();
    }
    public function test_revoke_releases_email_but_old_sessions_and_codes_stay_invalid(): void
    {
        $token=$this->accept();$this->change('revoke',1)->assertOk();
        $this->assertNull($this->member()->active_email_hash);$this->invite('manager')->assertOk();
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertUnauthorized();
        $this->assertSame(2,PartnerTeamMember::count());
    }
    public function test_deleted_membership_never_becomes_an_owner_session(): void
    {
        $token=$this->accept();$this->member()->delete();
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertUnauthorized();
    }
    public function test_expiry_and_resend_rotation_invalidate_previous_invitation_and_code(): void
    {
        $this->invite()->assertOk();$old=$this->invitationToken();$code=$this->challenge($old);
        $this->change('resend')->assertStatus(429);$this->travel(61)->seconds();
        $this->change('resend')->assertOk();$new=$this->invitationToken();$this->assertNotSame($old,$new);
        $this->postJson('/api/public/partner-team/invitation',['token'=>$old])->assertNotFound();
        $this->postJson('/api/partner-auth/verify',$code)->assertUnprocessable();
        $this->travel(8)->days();$this->postJson('/api/public/partner-team/invitation',['token'=>$new])->assertNotFound();
    }
    public function test_staff_cannot_choose_owner_role_or_use_another_email_invitation(): void
    {
        $this->invite('owner')->assertUnprocessable();$this->invite()->assertOk();
        $this->postJson('/api/partner-auth/code',['email'=>'other@example.test','mode'=>'staff','invitation_token'=>$this->invitationToken()])->assertStatus(202);
        Mail::assertNotSent(PartnerLoginCodeMail::class);
        $this->invite()->assertUnprocessable();
    }
    public function test_owner_cannot_invite_self_and_audit_failure_rolls_back_membership(): void
    {
        $this->withToken($this->owner)->postJson('/api/partner-auth/team',['request_key'=>(string)Str::uuid(),'name'=>'Owner','email'=>'owner@example.test','role'=>'manager'])->assertUnprocessable();
        \Illuminate\Support\Facades\Schema::drop('partner_team_events');
        try { app(\App\Services\PartnerTeamService::class)->invite($this->business,['request_key'=>(string)Str::uuid(),'name'=>'Staff','email'=>'staff@example.test','role'=>'manager'],1);$this->fail('Audit must be atomic'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame(0,PartnerTeamMember::count());Mail::assertNothingSent(); }
    }
    public function test_mail_failure_preserves_invitation_for_explicit_resend(): void
    {
        Mail::shouldReceive('to')->once()->andReturnSelf();Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Simulated delivery failure'));
        $this->invite()->assertOk()->assertJsonPath('member.deliveryStatus','failed');
        $this->assertSame('invited',$this->member()->status);$this->assertNotNull($this->member()->invitation_hash);
    }
    public function test_nonbusiness_or_unapproved_owner_cannot_invite(): void
    {
        $this->business->update(['status'=>'under_review']);$this->invite()->assertForbidden();
        $this->business->update(['status'=>'approved','application_payload'=>['type'=>'content_creator','language'=>'en','profile'=>['email'=>'owner@example.test']]]);$this->invite()->assertForbidden();
    }
    public function test_revoked_invitation_and_membership_from_other_business_are_inaccessible(): void
    {
        $this->invite()->assertOk();$token=$this->invitationToken();$this->change('revoke')->assertOk();
        $this->postJson('/api/public/partner-team/invitation',['token'=>$token])->assertNotFound();
        $other=$this->business->replicate();$other->reference='TP-'.Str::uuid();$other->submission_key=(string)Str::uuid();$other->save();
        $this->member()->update(['partner_application_id'=>$other->id]);$this->change('revoke',1)->assertNotFound();
    }
}
