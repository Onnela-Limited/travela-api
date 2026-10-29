<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Only migrate this feature into an isolated, in-memory database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_25_000001_create_partner_applications_table.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_partner_authentication.php'))->up();
        (require database_path('migrations/2026_10_26_000002_add_partner_application_reviews.php'))->up();
        Storage::fake('local');
    }

    public function test_individual_application_stores_private_documents_and_encrypted_details(): void
    {
        $response = $this->sendApplication();
        $response->assertCreated()->assertJsonPath('application.status', 'under_review');
        $record = PartnerApplication::sole();
        $response->assertJsonPath('application.reference', $record->reference);
        $this->assertSame('applicant@example.test', $record->application_payload['profile']['email']);
        $this->assertCount(3, $record->documents);
        foreach ($record->documents as $path) {
            Storage::disk('local')->assertExists($path);
            $this->assertStringStartsWith('partner-applications/', $path);
        }
        $raw = DB::table('partner_applications')->first();
        $this->assertStringNotContainsString('applicant@example.test', $raw->application_payload);
        $this->assertStringNotContainsString('1234567890', $raw->application_payload);
        $this->assertStringNotContainsString('partner-applications/', $raw->documents);
        $this->assertArrayNotHasKey('documents', $response->json('application'));
        $this->assertArrayNotHasKey('profile', $response->json('application'));
    }

    public function test_business_requires_registration_and_director_documents(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'hotel';
        $payload['identity']['brelaNo'] = 'BRELA-123';
        $payload['identity']['tin'] = 'TIN-123';
        $this->sendApplication($payload, ['id_front', 'id_back'])
            ->assertUnprocessable()->assertJsonValidationErrors(['documents.registration', 'documents.director_id']);
        $this->assertSame(0, PartnerApplication::count());
        $this->sendApplication($payload, ['id_front', 'id_back', 'registration', 'director_id'])->assertCreated();
        $this->assertCount(4, PartnerApplication::sole()->documents);
    }

    public function test_invalid_fields_or_missing_consent_do_not_create_an_application(): void
    {
        $payload = $this->payload();
        $payload['profile']['email'] = 'invalid';
        $payload['consent']['terms'] = false;
        $this->sendApplication($payload, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['application.profile.email', 'application.consent.terms', 'documents.id_front']);
        $this->assertSame(0, PartnerApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_and_oversized_documents_are_rejected(): void
    {
        $data = [
            'submission_key' => (string) Str::uuid(),
            'application' => json_encode($this->payload()),
            'documents' => [
                'id_front' => UploadedFile::fake()->create('fake.jpg', 1, 'text/plain'),
                'id_back' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
                'selfie' => UploadedFile::fake()->image('selfie.jpg', 700, 500),
            ],
        ];
        $this->withHeader('Accept', 'application/json')->post('/api/public/partner-applications', $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['documents.id_front', 'documents.id_back']);
        $this->assertSame(0, PartnerApplication::count());
    }

    public function test_retry_returns_same_reference_without_duplicate_documents(): void
    {
        $key = (string) Str::uuid();
        $first = $this->sendApplication(key: $key)->assertCreated();
        $this->sendApplication(key: $key)->assertOk()
            ->assertJsonPath('application.reference', $first->json('application.reference'));
        $this->assertSame(1, PartnerApplication::count());
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_failed_database_write_cleans_up_uploaded_documents(): void
    {
        PartnerApplication::creating(function () {
            throw new \RuntimeException('Simulated database failure');
        });
        try {
            $this->sendApplication()->assertStatus(500);
            $this->assertSame(0, PartnerApplication::count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            PartnerApplication::flushEventListeners();
        }
    }

    public function test_client_cannot_choose_approved_status(): void
    {
        $payload = $this->payload();
        $payload['status'] = 'approved';
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application');
        $this->assertSame(0, PartnerApplication::count());
    }

    private function sendApplication(?array $payload = null, array $kinds = ['id_front', 'id_back', 'selfie'], ?string $key = null)
    {
        $documents = [];
        foreach ($kinds as $kind) {
            $documents[$kind] = UploadedFile::fake()->image("$kind.jpg", 700, 500);
        }

        return $this->withHeader('Accept', 'application/json')->post('/api/public/partner-applications', [
            'submission_key' => $key ?? (string) Str::uuid(),
            'application' => json_encode($payload ?? $this->payload()),
            'documents' => $documents,
        ]);
    }

    private function payload(): array
    {
        return [
            'type' => 'content_creator',
            'language' => 'en',
            'profile' => ['displayName' => 'Test Creator', 'legalName' => 'Test Applicant', 'country' => 'Tanzania', 'city' => 'Dar es Salaam', 'phone' => '+255712345678', 'email' => 'applicant@example.test'],
            'identity' => ['idType' => 'nida', 'idNumber' => '1234567890', 'brelaNo' => '', 'tin' => '', 'licenceNo' => ''],
            'audience' => ['handles' => [['platform' => 'instagram', 'handle' => 'test_creator']], 'followerRange' => '', 'rooms' => '', 'monthlyGuests' => '', 'annualTours' => ''],
            'payout' => ['method' => 'mpesa', 'accountName' => 'Test Applicant', 'accountNumber' => '0712345678', 'bank' => '', 'branch' => '', 'nameConfirmed' => true],
            'consent' => ['terms' => true, 'privacy' => true, 'dataProcessing' => true],
        ];
    }
}
