<?php
namespace Tests\Feature;
use App\Models\{PartnerApplication,PartnerTeamMember};
use Illuminate\Support\Facades\{DB,Schema,Mail,Http,Crypt};
use Illuminate\Support\Str;
use App\Support\PartnerIdentity;
use Tests\TestCase;

class PartnerSettingsTest extends TestCase
{
 private PartnerApplication $partner;
 private string $token;
 protected function setUp(): void {
  parent::setUp();$this->assertSame(':memory:',config('database.connections.sqlite.database'));Mail::fake();Http::preventStrayRequests();
  foreach(['2026_10_25_000001_create_partner_applications_table','2026_10_26_000001_add_partner_authentication','2026_11_01_000001_create_partner_teams','2026_11_01_000002_create_partner_settings'] as $m)(require database_path('migrations/'.$m.'.php'))->up();
  $this->partner=PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'status'=>'approved','email_hash'=>PartnerIdentity::emailHash('owner@example.test'),
   'application_payload'=>['type'=>'hotel','language'=>'en','profile'=>['displayName'=>'Original','legalName'=>'Legal','email'=>'owner@example.test','country'=>'TZ','city'=>'Dar','phone'=>'+255712345678'],
    'payout'=>['method'=>'mpesa','accountName'=>'Owner','accountNumber'=>'0712345678','bank'=>'','branch'=>'','nameConfirmed'=>true], 'identity'=>['idNumber'=>'PRIVATE-ID'],'consent'=>['terms'=>true]],'documents'=>['id'=>'private/file'],'submitted_at'=>now()]);
  $this->token=$this->sessionToken();$this->withToken($this->token);
 }
 private function sessionToken(?PartnerTeamMember $member=null): string {
  $token=Str::random(64);DB::table('partner_sessions')->insert(['partner_application_id'=>$this->partner->id,'partner_team_member_id'=>$member?->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);return $token;
 }
 private function body(): array {return ['expected_version'=>0,'profile'=>['displayName'=>'Updated hotel','country'=>'Tanzania','city'=>'Arusha','phone'=>'+255712345679'],
  'payout'=>['method'=>'bank','accountName'=>'Owner','accountNumber'=>'NEW-PRIVATE-ACCOUNT','bank'=>'Test Bank','branch'=>'Test branch','nameConfirmed'=>true]];}
 public function test_owner_reads_private_settings_and_saves_encrypted_audited_changes(): void {
  $this->getJson('/api/partner-auth/settings')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('settings.version',0)->assertJsonMissingPath('settings.documents');
  $this->postJson('/api/partner-auth/settings',$this->body())->assertOk()->assertJsonPath('settings.version',1)->assertJsonPath('settings.profile.displayName','Updated hotel');
  $this->getJson('/api/partner-auth/settings')->assertJsonPath('settings.payout.accountNumber','NEW-PRIVATE-ACCOUNT');
  $fresh=$this->partner->fresh();$this->assertSame('Legal',$fresh->application_payload['profile']['legalName']);$this->assertSame('owner@example.test',$fresh->application_payload['profile']['email']);
  $this->assertSame('PRIVATE-ID',$fresh->application_payload['identity']['idNumber']);$this->assertSame(['id'=>'private/file'],$fresh->documents);$this->assertSame('approved',$fresh->status);
  foreach([DB::table('partner_applications')->value('application_payload'),DB::table('partner_settings_events')->value('changes')] as $encrypted)$this->assertStringNotContainsString('NEW-PRIVATE-ACCOUNT',$encrypted);
  $audit=json_decode(Crypt::decryptString(DB::table('partner_settings_events')->value('changes')),true);$this->assertSame('0712345678',$audit['before']['payout']['accountNumber']);
  Mail::assertNothingSent();Http::assertNothingSent();
 }
 public function test_anonymous_staff_and_unapproved_accounts_cannot_read_or_write(): void {
  $this->withToken('')->getJson('/api/partner-auth/settings')->assertUnauthorized();
  foreach(['manager','front_desk'] as $role){$hash=PartnerIdentity::emailHash($role.'@example.test');$m=PartnerTeamMember::create(['partner_application_id'=>$this->partner->id,'request_key'=>(string)Str::uuid(),'email_hash'=>$hash,'active_email_hash'=>$hash,'details'=>['name'=>'Staff','email'=>$role.'@example.test'],'role'=>$role,'status'=>'active']);$this->withToken($this->sessionToken($m));$this->getJson('/api/partner-auth/settings')->assertForbidden();$this->postJson('/api/partner-auth/settings',$this->body())->assertForbidden();}
  $this->partner->update(['status'=>'suspended']);$this->withToken($this->token)->getJson('/api/partner-auth/settings')->assertForbidden();$this->postJson('/api/partner-auth/settings',$this->body())->assertForbidden();
 }
 public function test_stale_updates_cannot_overwrite_current_settings(): void {
  $this->postJson('/api/partner-auth/settings',$this->body())->assertOk();
  $this->postJson('/api/partner-auth/settings',$this->body())->assertConflict();$this->assertSame(1,DB::table('partner_settings_events')->count());
 }
 public function test_validates_bank_confirmation_phone_and_immutable_profile_fields(): void {
  foreach([['payout.bank'=>''],['payout.nameConfirmed'=>false],['profile.phone'=>'invalid'],['profile.email'=>'attacker@example.test'],['profile.legalName'=>'Different person'],['payout.method'=>'unsupported'],['profile.displayName'=>'']] as $patch){$body=$this->body();foreach($patch as $key=>$value)data_set($body,$key,$value);$this->postJson('/api/partner-auth/settings',$body)->assertUnprocessable();}
  $this->assertSame(0,DB::table('partner_settings_events')->count());
 }
 public function test_audit_failure_rolls_back_settings_update(): void {
  Schema::drop('partner_settings_events');$this->postJson('/api/partner-auth/settings',$this->body())->assertStatus(500);
  $this->assertSame('Original',$this->partner->fresh()->application_payload['profile']['displayName']);$this->assertSame(0,(int)$this->partner->fresh()->settings_version);
 }
 public function test_individual_owner_can_save_mobile_money_and_clears_irrelevant_bank_fields(): void {
  $payload=$this->partner->application_payload;$payload['type']='content_creator';$this->partner->update(['application_payload'=>$payload]);$body=$this->body();$body['payout']['method']='mixx';
  $this->postJson('/api/partner-auth/settings',$body)->assertOk()->assertJsonPath('settings.payout.bank','')->assertJsonPath('settings.payout.branch','');
 }
}
