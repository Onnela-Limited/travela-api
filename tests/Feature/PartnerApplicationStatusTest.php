<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerApplicationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_25_000001_create_partner_applications_table.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_partner_authentication.php'))->up();
        (require database_path('migrations/2026_10_26_000002_add_partner_application_reviews.php'))->up();
    }

    public function test_returns_current_status_for_every_review_outcome_without_private_details(): void
    {
        $application = $this->application();
        foreach (['under_review', 'info_requested', 'approved', 'rejected', 'suspended'] as $status) {
            $application->update(['status' => $status]);
            $this->lookup($application)->assertOk()->assertExactJson(['application' => [
                'reference' => $application->reference,
                'status' => $status,
                'submittedAt' => $application->submitted_at->toIso8601String(),
            ]])->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame(1, PartnerApplication::count());
    }

    public function test_reference_alone_cannot_read_status(): void
    {
        $application = $this->application();
        $this->postJson('/api/public/partner-applications/status', ['reference' => $application->reference])
            ->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    }

    public function test_wrong_key_and_unknown_reference_return_the_same_response(): void
    {
        $application = $this->application();
        $wrongKey = $this->postJson('/api/public/partner-applications/status', [
            'reference' => $application->reference,
            'submission_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $unknown = $this->postJson('/api/public/partner-applications/status', [
            'reference' => 'TP-2026-UNKNOWN',
            'submission_key' => $application->submission_key,
        ])->assertNotFound();
        $this->assertSame($wrongKey->json(), $unknown->json());
        $wrongKey->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_key_for_another_application_does_not_allow_access(): void
    {
        $first = $this->application();
        $second = $this->application();
        $this->postJson('/api/public/partner-applications/status', [
            'reference' => $first->reference,
            'submission_key' => $second->submission_key,
        ])->assertNotFound();
    }

    public function test_status_lookup_does_not_change_application_data(): void
    {
        $application = $this->application();
        $before = $application->fresh()->getRawOriginal();
        $this->lookup($application)->assertOk();
        $this->assertSame($before, $application->fresh()->getRawOriginal());
    }

    public function test_get_requests_and_malformed_credentials_are_rejected(): void
    {
        $this->getJson('/api/public/partner-applications/status')->assertStatus(405);
        $this->postJson('/api/public/partner-applications/status', [
            'reference' => str_repeat('x', 65),
            'submission_key' => 'bad-key',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference', 'submission_key']);
    }

    private function lookup(PartnerApplication $application)
    {
        return $this->postJson('/api/public/partner-applications/status', [
            'reference' => $application->reference,
            'submission_key' => $application->submission_key,
        ]);
    }

    private function application(): PartnerApplication
    {
        return PartnerApplication::create([
            'submission_key' => (string) Str::uuid(),
            'reference' => 'TP-2026-'.Str::upper((string) Str::uuid()),
            'status' => 'under_review',
            'application_payload' => ['profile' => ['email' => 'private@example.test'], 'identity' => ['idNumber' => 'PRIVATE-ID']],
            'documents' => ['id_front' => 'private/document.jpg'],
            'submitted_at' => now(),
        ]);
    }
}
