<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\PartnerApplicationReview;
use App\Models\User;
use App\Support\PartnerIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerApplicationReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', fn (Blueprint $table) => $table->string('role')->default('user'));
        (require database_path('migrations/2025_10_05_114523_create_personal_access_tokens_table.php'))->up();
        (require database_path('migrations/2026_10_25_000001_create_partner_applications_table.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_partner_authentication.php'))->up();
        (require database_path('migrations/2026_10_26_000002_add_partner_application_reviews.php'))->up();
        Storage::fake('local');
    }

    public function test_every_review_route_requires_admin_authentication(): void
    {
        $application = $this->application();
        $this->getJson('/api/admin/partner-applications')->assertUnauthorized();
        $this->getJson("/api/admin/partner-applications/$application->id")->assertUnauthorized();
        $this->getJson("/api/admin/partner-applications/$application->id/documents/id_front")->assertUnauthorized();
        $this->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => 'approved', 'expected_version' => 0])->assertUnauthorized();
        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_agents_and_customer_accounts_cannot_review_or_read_documents(): void
    {
        $application = $this->application();
        foreach (['agent', 'user'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/admin/partner-applications')->assertForbidden();
            $this->getJson("/api/admin/partner-applications/$application->id")->assertForbidden();
            $this->getJson("/api/admin/partner-applications/$application->id/documents/id_front")->assertForbidden();
            $this->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => 'approved', 'expected_version' => 0])->assertForbidden();
        }
    }

    public function test_partner_session_cannot_be_used_as_an_admin_token(): void
    {
        $application = $this->application();
        $token = Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id' => $application->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token)->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => 'approved', 'expected_version' => 0])->assertUnauthorized();
        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_list_supports_status_reference_and_pagination_without_private_fields(): void
    {
        $this->admin();
        $first = $this->application();
        $this->application();
        $this->application('approved');
        $this->getJson('/api/admin/partner-applications?status=under_review&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.lastPage', 2)->assertJsonCount(1, 'applications')
            ->assertJsonMissingPath('applications.0.details')->assertJsonMissingPath('applications.0.documents')
            ->assertJsonMissingPath('applications.0.submission_key');
        $this->getJson('/api/admin/partner-applications?reference='.$first->reference)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('applications.0.id', $first->id);
        $this->getJson('/api/admin/partner-applications?per_page=500')->assertUnprocessable();
    }

    public function test_admin_can_read_details_and_private_document_without_public_storage_paths(): void
    {
        $this->admin();
        $application = $this->application();
        $file = UploadedFile::fake()->image('front.jpg', 600, 400);
        $path = "partner-applications/$application->reference/front.jpg";
        Storage::disk('local')->put($path, $file->get());
        $application->update(['documents' => ['id_front' => $path]]);
        $this->getJson("/api/admin/partner-applications/$application->id")->assertOk()
            ->assertJsonPath('application.details.identity.idNumber', 'PRIVATE-ID')->assertJsonPath('application.documents.0', 'id_front')
            ->assertJsonMissingPath('application.submission_key')->assertDontSee($path);
        $response = $this->get("/api/admin/partner-applications/$application->id/documents/id_front");
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($file->get(), $response->streamedContent());
    }

    public function test_missing_unknown_and_out_of_application_documents_are_rejected(): void
    {
        $this->admin();
        $application = $this->application();
        $this->getJson("/api/admin/partner-applications/$application->id/documents/id_front")->assertNotFound();
        $this->getJson("/api/admin/partner-applications/$application->id/documents/unknown")->assertNotFound();
        Storage::disk('local')->put('partner-applications/other/private.pdf', '%PDF-test');
        $application->update(['documents' => ['id_front' => 'partner-applications/other/private.pdf']]);
        $this->getJson("/api/admin/partner-applications/$application->id/documents/id_front")->assertNotFound();
    }

    public function test_request_information_message_reaches_partner_and_is_encrypted_in_audit(): void
    {
        $admin = $this->admin();
        $application = $this->application();
        $message = 'Please provide a clearer business registration document.';
        $this->review($application, 'info_requested', 0, $message)->assertOk()
            ->assertJsonPath('application.status', 'info_requested')->assertJsonPath('application.reviewVersion', 1)
            ->assertJsonPath('application.history.0.reviewer', $admin->name)->assertJsonPath('application.history.0.message', $message);
        $this->postJson('/api/public/partner-applications/status', ['reference' => $application->reference, 'submission_key' => $application->submission_key])->assertOk()
            ->assertJsonPath('application.status', 'info_requested')->assertJsonPath('application.reviewMessage', $message)
            ->assertJsonMissingPath('application.reviewer_id')->assertJsonMissingPath('application.history');
        $this->assertStringNotContainsString($message, DB::table('partner_applications')->where('id', $application->id)->value('review_message'));
        $this->assertStringNotContainsString($message, DB::table('partner_application_reviews')->sole()->message);
    }

    public function test_approval_after_information_request_updates_authenticated_partner_and_preserves_history(): void
    {
        $this->admin();
        $application = $this->application();
        $this->review($application, 'info_requested', 0, 'Please confirm your details.')->assertOk();
        $this->review($application, 'approved', 1)->assertOk()->assertJsonPath('application.status', 'approved')
            ->assertJsonCount(2, 'application.history')->assertJsonPath('application.reviewMessage', null);
        $token = Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id' => $application->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertOk()->assertJsonPath('partner.status', 'approved')->assertJsonPath('partner.reviewMessage', null);
    }

    public function test_rejection_requires_a_reason_and_reaches_partner(): void
    {
        $this->admin();
        $application = $this->application();
        $this->review($application, 'rejected', 0)->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->review($application, 'info_requested', 0, '   ')->assertUnprocessable();
        $this->review($application, 'rejected', 0, 'Registration could not be verified.')->assertOk();
        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame(1, PartnerApplicationReview::count());
    }

    public function test_stale_review_is_rejected_without_overwriting_another_admin(): void
    {
        $first = $this->admin();
        $application = $this->application();
        $this->review($application, 'info_requested', 0, 'Please replace the ID photo.')->assertOk();
        $this->admin();
        $this->review($application, 'approved', 0)->assertStatus(409);
        $this->assertSame('info_requested', $application->fresh()->status);
        $this->assertSame($first->id, PartnerApplicationReview::sole()->reviewer_id);
        $this->review($application, 'approved', 1)->assertOk();
    }

    public function test_completed_or_suspended_applications_cannot_be_reviewed_again(): void
    {
        $this->admin();
        foreach (['approved', 'rejected', 'suspended'] as $status) {
            $this->review($this->application($status), 'approved', 0)->assertStatus(409);
        }
        $this->assertSame(0, PartnerApplicationReview::count());
    }

    public function test_invalid_decisions_or_messages_do_not_change_an_application(): void
    {
        $this->admin();
        $application = $this->application();
        $this->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => 'suspended', 'expected_version' => 0])->assertUnprocessable();
        $this->review($application, 'info_requested', 0, str_repeat('x', 2001))->assertUnprocessable();
        $this->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => 'approved'])->assertUnprocessable();
        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_audit_failure_rolls_back_the_decision(): void
    {
        $this->admin();
        $application = $this->application();
        PartnerApplicationReview::creating(function () { throw new \RuntimeException('Simulated audit failure'); });
        try {
            $this->review($application, 'approved', 0)->assertStatus(500);
            $this->assertSame('under_review', $application->fresh()->status);
            $this->assertSame(0, $application->fresh()->review_version);
        } finally { PartnerApplicationReview::flushEventListeners(); }
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function review(PartnerApplication $application, string $decision, int $version, string $message = '')
    {
        return $this->postJson("/api/admin/partner-applications/$application->id/review", ['decision' => $decision, 'expected_version' => $version, 'message' => $message]);
    }

    private function application(string $status = 'under_review'): PartnerApplication
    {
        return PartnerApplication::create([
            'submission_key' => (string) Str::uuid(), 'reference' => 'TP-2026-'.Str::upper((string) Str::uuid()), 'status' => $status,
            'email_hash' => PartnerIdentity::emailHash('applicant@example.test'),
            'application_payload' => ['type' => 'hotel', 'language' => 'en', 'profile' => ['displayName' => 'Test Hotel', 'legalName' => 'Test Hotel Ltd', 'email' => 'applicant@example.test', 'country' => 'Tanzania', 'city' => 'Dar es Salaam', 'phone' => '+255700000000'], 'identity' => ['idNumber' => 'PRIVATE-ID'], 'payout' => ['accountNumber' => 'PRIVATE-ACCOUNT']],
            'documents' => [], 'submitted_at' => now(),
        ]);
    }
}
