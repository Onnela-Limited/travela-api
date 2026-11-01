<?php
namespace Tests\Feature;
use App\Mail\PartnerUpdateMail;
use App\Models\{PartnerApplication,PartnerTeamMember};
use App\Services\PartnerNotificationService;
use App\Support\PartnerIdentity;
use Illuminate\Support\Facades\{DB,Mail,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class PartnerNotificationTest extends TestCase
{
 private PartnerApplication $partner;
 private PartnerNotificationService $service;
 protected function setUp(): void {
  parent::setUp();$this->assertSame(':memory:',config('database.connections.sqlite.database'));Mail::fake();Http::preventStrayRequests();config(['cache.default'=>'array','partners.portal_url'=>'https://partners.example.test']);
  foreach(['2026_10_25_000001_create_partner_applications_table','2026_10_26_000001_add_partner_authentication','2026_11_01_000001_create_partner_teams','2026_11_01_000003_create_partner_notifications'] as $m)(require database_path('migrations/'.$m.'.php'))->up();
  $this->partner=PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'email_hash'=>PartnerIdentity::emailHash('owner@example.test'),'status'=>'approved','application_payload'=>['type'=>'hotel','profile'=>['email'=>'owner@example.test']],'documents'=>[],'submitted_at'=>now()]);$this->service=app(PartnerNotificationService::class);
 }
 private function staff(string $role): PartnerTeamMember {$email=$role.Str::random(5).'@example.test';$hash=PartnerIdentity::emailHash($email);return PartnerTeamMember::create(['partner_application_id'=>$this->partner->id,'request_key'=>(string)Str::uuid(),'email_hash'=>$hash,'active_email_hash'=>$hash,'role'=>$role,'status'=>'active','details'=>['name'=>'Staff','email'=>$email]]);}
 private function asPartner(?PartnerTeamMember $member=null): void {$token=Str::random(64);DB::table('partner_sessions')->insert(['partner_application_id'=>$this->partner->id,'partner_team_member_id'=>$member?->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);$this->withToken($token);}
 private function record(string $key='bulk:1:1',string $category='bulk'): void {$paths=['bulk'=>'/dashboard/bulk','stock'=>'/dashboard/stock','application'=>'/status','payout'=>'/dashboard/payouts'];$this->service->record($this->partner,$key,$category,'Test update','A status changed. Open details.',$paths[$category]);}
 public function test_role_scoped_recipients_deduplication_and_encryption(): void {
  $manager=$this->staff('manager');$desk=$this->staff('front_desk');$this->record();$this->record();$this->record('stock:1:1','stock');$this->record('payout:1:1','payout');$this->record('app:1:1','application');
  $this->assertSame(7,DB::table('partner_notifications')->count());
  $this->assertSame(1,$this->service->scope($this->partner,$desk)->count());$this->assertSame(2,$this->service->scope($this->partner,$manager)->count());
  $raw=DB::table('partner_notifications')->first();$this->assertStringNotContainsString('owner@example.test',$raw->email);$this->assertStringNotContainsString('Test update',$raw->content);Mail::assertNothingSent();
 }
 public function test_list_and_mark_read_are_private_recipient_scoped_and_idempotent(): void {
  $manager=$this->staff('manager');$this->record();$this->asPartner();$this->getJson('/api/partner-auth/notifications')->assertOk()->assertJsonPath('unread',1)->assertJsonMissingPath('notifications.0.email')->assertJsonMissingPath('notifications.0.email_status');
  $ownerId=DB::table('partner_notifications')->where('recipient_key','owner')->value('id');$this->asPartner($manager);
  $this->postJson('/api/partner-auth/notifications/'.$ownerId.'/read')->assertNotFound();
  $id=DB::table('partner_notifications')->where('partner_team_member_id',$manager->id)->value('id');$this->postJson('/api/partner-auth/notifications/'.$id.'/read')->assertOk();$first=DB::table('partner_notifications')->where('id',$id)->value('read_at');
  $this->travel(1)->minutes();$this->postJson('/api/partner-auth/notifications/'.$id.'/read')->assertOk();$this->assertSame($first,DB::table('partner_notifications')->where('id',$id)->value('read_at'));$this->getJson('/api/partner-auth/notifications')->assertJsonPath('unread',0);
 }
 public function test_role_downgrade_hides_old_financial_updates_and_revocation_blocks_delivery(): void {
  $manager=$this->staff('manager');$this->record();$id=DB::table('partner_notifications')->where('partner_team_member_id',$manager->id)->value('id');$manager->update(['role'=>'front_desk']);$this->asPartner($manager);
  $this->getJson('/api/partner-auth/notifications')->assertJsonCount(0,'notifications');$this->postJson('/api/partner-auth/notifications/'.$id.'/read')->assertNotFound();$this->assertFalse($this->service->deliver($id));
  $this->record('stock:1:2','stock');$id=DB::table('partner_notifications')->where('partner_team_member_id',$manager->id)->latest('id')->value('id');$manager->update(['status'=>'revoked','active_email_hash'=>null]);$this->assertFalse($this->service->deliver($id));$this->getJson('/api/partner-auth/notifications')->assertUnauthorized();Mail::assertNotSent(PartnerUpdateMail::class,fn($mail)=>$mail->hasTo($manager->details['email']));
 }
 public function test_delivery_is_once_after_commit_and_failed_delivery_retries_without_losing_inbox(): void {
  $this->record();$id=DB::table('partner_notifications')->value('id');$this->assertTrue($this->service->deliver($id));$this->assertFalse($this->service->deliver($id));Mail::assertSent(PartnerUpdateMail::class,1);
  $this->record('bulk:1:2');$failed=DB::table('partner_notifications')->latest('id')->value('id');
  $fake=Mail::getFacadeRoot();Mail::shouldReceive('to')->once()->andReturnSelf();Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Simulated failure'));$this->assertFalse($this->service->deliver($failed));$this->assertSame('failed',DB::table('partner_notifications')->where('id',$failed)->value('email_status'));
  Mail::swap($fake);$this->assertSame(0,$this->service->retry());$this->travel(61)->seconds();$this->assertSame(1,$this->service->retry());Mail::assertSent(PartnerUpdateMail::class,2);$this->assertSame(2,DB::table('partner_notifications')->count());
 }
 public function test_rollback_creates_no_notification_and_no_email(): void {
  try{DB::transaction(function(){$this->record();throw new \RuntimeException('Rollback');});}catch(\RuntimeException){}
  $this->assertSame(0,DB::table('partner_notifications')->count());$this->assertSame(0,$this->service->retry());Mail::assertNothingSent();
 }
 public function test_other_business_cannot_read_or_mark_notification_and_pagination_is_bounded(): void {
  $this->record();$id=DB::table('partner_notifications')->value('id');$other=$this->partner->replicate();$other->submission_key=(string)Str::uuid();$other->reference='TP-'.Str::uuid();$other->save();$this->partner=$other;$this->asPartner();
  $this->getJson('/api/partner-auth/notifications')->assertJsonCount(0,'notifications');$this->postJson('/api/partner-auth/notifications/'.$id.'/read')->assertNotFound();$this->getJson('/api/partner-auth/notifications?page=0')->assertUnprocessable();
 }
 public function test_exhausted_attempts_do_not_retry_and_anonymous_requests_are_rejected(): void {
  $this->record();DB::table('partner_notifications')->update(['attempts'=>5,'email_status'=>'failed']);$this->assertSame(0,$this->service->retry());$this->getJson('/api/partner-auth/notifications')->assertUnauthorized();Mail::assertNothingSent();
 }
}
