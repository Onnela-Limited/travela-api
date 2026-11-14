<?php
namespace Tests\Feature;

use App\Models\{PartnerApplication,PartnerApplicationReview,PartnerTeamMember};
use App\Support\PartnerIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Schema,Storage,Mail,Http,Crypt};
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerApplicationRevisionTest extends TestCase {
    private PartnerApplication $partner;
    protected function setUp(): void {
        parent::setUp();$this->assertSame(':memory:',config('database.connections.sqlite.database'));Mail::fake();Http::preventStrayRequests();Storage::fake('local');
        foreach (['2026_10_25_000001_create_partner_applications_table','2026_10_26_000001_add_partner_authentication','2026_10_26_000002_add_partner_application_reviews','2026_11_01_000001_create_partner_teams','2026_11_13_000001_create_partner_application_revisions'] as $migration) (require database_path('migrations/'.$migration.'.php'))->up();
        $this->partner=$this->application();$this->asOwner($this->partner);
    }
    private function application(string $status='info_requested'): PartnerApplication {
        $reference='TP-'.Str::uuid();$documents=[];
        foreach (['id_front','id_back','selfie'] as $kind) $documents[$kind]=UploadedFile::fake()->image($kind.'.jpg')->store('partner-applications/'.$reference,'local');
        return PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>$reference,'status'=>$status,'review_version'=>3,'review_message'=>'Correct the city.','reviewed_at'=>now(),'submitted_at'=>now()->subDays(2),'documents'=>$documents,'email_hash'=>PartnerIdentity::emailHash('owner@example.test'),'application_payload'=>[
            'type'=>'content_creator','language'=>'en','profile'=>['firstName'=>'Test','lastName'=>'Partner','displayName'=>'Test Partner','legalName'=>'Test Partner','country'=>'Tanzania','city'=>'Old city','phone'=>'+255712345678','phoneCountry'=>'TZ','email'=>'owner@example.test'],
            'identity'=>['idType'=>'passport','idNumber'=>'PRIVATE-ID','brelaNo'=>'','tin'=>'','licenceNo'=>''],
            'audience'=>['handles'=>[],'followerRange'=>'','rooms'=>null,'annualTours'=>null],
            'payout'=>['method'=>'mpesa','country'=>'TZ','accountName'=>'Test Partner','accountNumber'=>'+255712345678','bank'=>'','branch'=>'','nameConfirmed'=>true],
            'consent'=>['terms'=>true,'privacy'=>true,'dataProcessing'=>true,'accuracy'=>true],
        ]]);
    }
    private function asOwner(PartnerApplication $app, ?PartnerTeamMember $member=null): void {
        $token=Str::random(64);DB::table('partner_sessions')->insert(['partner_application_id'=>$app->id,'partner_team_member_id'=>$member?->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);$this->withToken($token);
    }
    private function body(): array {
        $payload=$this->partner->application_payload;$payload['profile']['city']='Corrected city';
        return ['application'=>json_encode($payload),'request_key'=>(string)Str::uuid(),'expected_version'=>3,'retained_documents'=>array_keys($this->partner->documents)];
    }
    private function send(array $body) { return $this->withHeader('Accept','application/json')->post('/api/partner-auth/application',$body); }
    public function test_only_the_authenticated_owner_can_read_or_resubmit(): void {
        $this->withToken('')->getJson('/api/partner-auth/application')->assertUnauthorized();$this->send($this->body())->assertUnauthorized();
        $payload=$this->partner->application_payload;$payload['type']='hotel';$this->partner->update(['application_payload'=>$payload,'status'=>'approved']);
        $hash=PartnerIdentity::emailHash('staff@example.test');$member=PartnerTeamMember::create(['partner_application_id'=>$this->partner->id,'request_key'=>(string)Str::uuid(),'email_hash'=>$hash,'active_email_hash'=>$hash,'details'=>['name'=>'Staff','email'=>'staff@example.test'],'role'=>'manager','status'=>'active']);$this->asOwner($this->partner,$member);
        $this->getJson('/api/partner-auth/application')->assertForbidden();$this->send($this->body())->assertForbidden();
    }
    public function test_private_edit_response_never_exposes_keys_or_document_paths_and_ignores_other_ids(): void {
        $other=$this->application();
        $response=$this->getJson('/api/partner-auth/application?partner_application_id='.$other->id)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('revision.reference',$this->partner->reference);
        $this->assertStringNotContainsString($this->partner->submission_key,$response->getContent());$this->assertStringNotContainsString('partner-applications/',$response->getContent());$response->assertJsonPath('revision.application.identity.idNumber','PRIVATE-ID');
    }
    public function test_info_requested_and_rejected_applications_return_to_review_without_changing_reference(): void {
        foreach (['info_requested','rejected'] as $status) {
            $this->partner=$this->application($status);$this->asOwner($this->partner);$original=$this->partner->documents;
            PartnerApplicationReview::create(['partner_application_id'=>$this->partner->id,'reviewer_id'=>1,'reviewer_name'=>'Admin','from_status'=>'under_review','to_status'=>$status,'message'=>'Original decision','created_at'=>now()]);
            $this->send($this->body())->assertOk()->assertJsonPath('application.reference',$this->partner->reference)->assertJsonPath('application.status','under_review');
            $fresh=$this->partner->fresh();$this->assertSame(4,$fresh->review_version);$this->assertSame($original,$fresh->documents);$this->assertSame($this->partner->submission_key,$fresh->submission_key);$this->assertNull($fresh->review_message);$this->assertNull($fresh->reviewed_at);$this->assertSame('Corrected city',$fresh->application_payload['profile']['city']);
            $this->assertSame(1,PartnerApplicationReview::where('partner_application_id',$fresh->id)->count());
            $audit=DB::table('partner_application_revisions')->where('partner_application_id',$fresh->id)->first();$this->assertSame($status,$audit->from_status);$this->assertStringNotContainsString('PRIVATE-ID',$audit->previous_details);
            $this->assertSame('Old city',json_decode(Crypt::decryptString($audit->previous_details),true)['profile']['city']);
        }
    }
    public function test_replaced_documents_and_previous_encrypted_snapshot_are_retained(): void {
        $body=$this->body();$body['retained_documents']=['id_back','selfie'];$body['documents']=['id_front'=>UploadedFile::fake()->image('replacement.jpg')];$old=$this->partner->documents;
        $this->send($body)->assertOk();$new=$this->partner->fresh()->documents;$this->assertNotSame($old['id_front'],$new['id_front']);$this->assertSame($old['id_back'],$new['id_back']);
        Storage::disk('local')->assertExists($old['id_front']);Storage::disk('local')->assertExists($new['id_front']);
        $audit=DB::table('partner_application_revisions')->first();$this->assertStringNotContainsString('partner-applications/',$audit->previous_documents);$this->assertSame($old,json_decode(Crypt::decryptString($audit->previous_documents),true));
    }
    public function test_missing_removed_or_invalid_documents_and_changed_email_are_rejected(): void {
        $body=$this->body();$body['retained_documents']=['id_back','selfie'];$this->send($body)->assertUnprocessable()->assertJsonValidationErrors('documents.id_front');
        $body['documents']=['id_front'=>UploadedFile::fake()->create('bad.exe',1,'text/plain')];$this->send($body)->assertUnprocessable()->assertJsonValidationErrors('documents.id_front');
        $body=$this->body();$payload=json_decode($body['application'],true);$payload['profile']['email']='attacker@example.test';$body['application']=json_encode($payload);$this->send($body)->assertUnprocessable()->assertJsonValidationErrors('application.profile.email');
        $this->assertSame(0,DB::table('partner_application_revisions')->count());$this->assertSame('info_requested',$this->partner->fresh()->status);
    }
    public function test_stale_forms_and_non_editable_states_cannot_overwrite_admin_decisions(): void {
        $body=$this->body();$body['expected_version']=2;$this->send($body)->assertConflict();
        foreach (['approved','under_review','suspended'] as $status) {
            $this->partner->update(['status'=>$status]);$this->getJson('/api/partner-auth/application')->assertConflict();$this->send($this->body())->assertConflict();
        }
        $this->assertSame(0,DB::table('partner_application_revisions')->count());
    }
    public function test_retries_are_idempotent_even_after_a_later_admin_decision(): void {
        $body=$this->body();$first=$this->send($body)->assertOk();$this->send($body)->assertOk()->assertExactJson($first->json());
        $this->partner->fresh()->update(['status'=>'rejected','review_version'=>5,'review_message'=>'Second decision']);
        $this->send($body)->assertOk()->assertJsonPath('application.status','rejected');$this->assertSame(1,DB::table('partner_application_revisions')->count());$this->assertSame(5,$this->partner->fresh()->review_version);
        $body['request_key']=(string)Str::uuid();$this->send($body)->assertConflict();
    }
    public function test_audit_failure_rolls_back_changes_and_removes_only_new_uploads(): void {
        DB::statement("CREATE TRIGGER fail_revision BEFORE INSERT ON partner_application_revisions BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $body=$this->body();$body['documents']=['id_front'=>UploadedFile::fake()->image('replacement.jpg')];$oldFiles=Storage::disk('local')->allFiles();
        $this->send($body)->assertStatus(500);$this->assertSame('info_requested',$this->partner->fresh()->status);$this->assertSame($oldFiles,Storage::disk('local')->allFiles());
    }
}
