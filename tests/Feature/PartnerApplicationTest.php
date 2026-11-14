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
        (require database_path('migrations/2026_10_29_000001_create_partner_referrals.php'))->up();
        (require database_path('migrations/2026_11_14_000001_create_partner_application_email_claims.php'))->up();
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

    public function test_optional_bank_fields_are_saved_and_invalid_swift_is_rejected(): void
    {
        $payload=$this->payload();$payload['payout']=array_merge($payload['payout'],['method'=>'bank','bank'=>'Test bank','swiftCode'=>' abcdtz12 ','bankAddress'=>'  Private bank address  ']);
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('ABCDTZ12',PartnerApplication::sole()->application_payload['payout']['swiftCode']);
        $this->assertSame('Private bank address',PartnerApplication::sole()->application_payload['payout']['bankAddress']);
        $payload['payout']['swiftCode']='INVALID';
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.payout.swiftCode');
    }

    public function test_mobile_money_country_and_international_number_are_validated(): void
    {
        $payload=$this->payload();$payload['payout']['country']=' tz ';$payload['payout']['accountNumber']='+255712345678';
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('TZ',PartnerApplication::sole()->application_payload['payout']['country']);
        foreach (['+254712345678', '0712345678', '+255123'] as $number) {
            $payload['payout']['accountNumber']=$number;
            $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.payout.accountNumber');
        }
        $payload['payout']['country']='ZZ';$this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.payout.country');
    }

    public function test_other_payout_requires_complete_instructions_and_clears_stale_fields(): void
    {
        $payload=$this->payload();$payload['payout']['method']='other';$payload['payout']['otherDetails']='   ';
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.payout.otherDetails');
        $payload['payout']['otherDetails']=str_repeat('a',2001);
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.payout.otherDetails');
        $payload['payout']['otherDetails']='  Tanzania; Test network; account OTHER-PRIVATE-123  ';$payload['payout']['country']='TZ';
        $this->sendApplication($payload)->assertCreated();$payout=PartnerApplication::sole()->application_payload['payout'];
        $this->assertSame('Tanzania; Test network; account OTHER-PRIVATE-123',$payout['otherDetails']);
        $this->assertEmpty($payout['accountNumber']);$this->assertArrayNotHasKey('country',$payout);
        $this->assertStringNotContainsString('OTHER-PRIVATE-123',DB::table('partner_applications')->value('application_payload'));
    }

    public function test_accuracy_confirmation_is_required_before_an_application_is_saved(): void
    {
        foreach ([null, false, 'no'] as $accuracy) {
            $payload=$this->payload();$payload['consent']['accuracy']=$accuracy;
            $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.consent.accuracy');
        }
        $payload=$this->payload();unset($payload['consent']['accuracy']);
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.consent.accuracy');
        $this->assertSame(0,PartnerApplication::count());$this->assertSame([],Storage::disk('local')->allFiles());
        $this->sendApplication()->assertCreated();
        $this->assertTrue(PartnerApplication::sole()->application_payload['consent']['accuracy']);
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
            $this->assertSame(0,DB::table('partner_application_email_claims')->count());
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

    public function test_first_and_last_names_are_saved_separately_and_compatibility_names_are_server_derived(): void
    {
        $payload = $this->payload();
        $payload['profile'] = [...$payload['profile'], 'firstName' => '  Anne-Marie  ', 'lastName' => "  O'Neill  ", 'displayName' => 'Spoofed display', 'legalName' => 'Spoofed identity'];
        $this->sendApplication($payload)->assertCreated();
        $profile = PartnerApplication::sole()->application_payload['profile'];
        $this->assertSame('Anne-Marie', $profile['firstName']);
        $this->assertSame("O'Neill", $profile['lastName']);
        $this->assertSame("Anne-Marie O'Neill", $profile['displayName']);
        $this->assertSame("Anne-Marie O'Neill", $profile['legalName']);
        $this->assertStringNotContainsString('Anne-Marie', DB::table('partner_applications')->value('application_payload'));
    }

    public function test_new_name_fields_require_both_names_and_enforce_length_without_legacy_fallback(): void
    {
        foreach ([['firstName' => 'Alice'], ['lastName' => 'Njeri'], ['firstName' => ' ', 'lastName' => 'Njeri'], ['firstName' => str_repeat('A', 71), 'lastName' => 'Njeri']] as $names) {
            $payload = $this->payload();
            $payload['profile'] = [...$payload['profile'], ...$names];
            $this->sendApplication($payload)->assertUnprocessable();
        }
        $this->assertSame(0, PartnerApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_new_application_needs_no_display_or_legal_name_and_accepts_unicode_names(): void
    {
        $payload = $this->payload();
        unset($payload['profile']['displayName'], $payload['profile']['legalName']);
        $payload['profile']['firstName'] = 'José';
        $payload['profile']['lastName'] = 'Mwinyi';
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('José Mwinyi', PartnerApplication::sole()->application_payload['profile']['displayName']);
    }

    public function test_phone_requires_international_digits_and_the_selected_country_prefix_and_length(): void
    {
        foreach (['0712345678', '+255 712345678', '+255abc345678', '+255123', '+255712345678999999', '+999712345678', '+254712345678', '+2550712345678'] as $phone) {
            $payload = $this->payload();
            $payload['profile']['phone'] = $phone;
            $payload['profile']['phoneCountry'] = 'TZ';
            $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.profile.phone');
        }
        $this->assertSame(0, PartnerApplication::count());
        $payload = $this->payload();
        $payload['profile']['phoneCountry'] = 'TZ';
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('+255712345678', PartnerApplication::sole()->application_payload['profile']['phone']);
        $this->assertSame('TZ', PartnerApplication::sole()->application_payload['profile']['phoneCountry']);
    }

    public function test_phone_country_must_be_supported_and_international_prefixes_are_preserved(): void
    {
        $payload = $this->payload();
        $payload['profile']['phoneCountry'] = 'ZZ';
        $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.profile.phoneCountry');
        $payload['profile']['phoneCountry'] = 'IT';
        $payload['profile']['phone'] = '+390212345678';
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('+390212345678', PartnerApplication::sole()->application_payload['profile']['phone']);
    }

    public function test_country_selection_rejects_arbitrary_text_and_saves_a_recognized_country(): void
    {
        foreach (['', 'Not a country', 'ZZ', ['TZ']] as $country) {
            $payload = $this->payload();
            $payload['profile']['country'] = $country;
            $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.profile.country');
        }
        $this->assertSame(0, PartnerApplication::count());
        $payload = $this->payload();
        $payload['profile']['country'] = 'KE';
        $this->sendApplication($payload)->assertCreated();
        $this->assertSame('Kenya', PartnerApplication::sole()->application_payload['profile']['country']);
    }

    public function test_country_validation_accepts_previous_country_names_and_swahili_selections(): void
    {
        foreach (['Tanzania' => 'Tanzania', 'tz' => 'Tanzania', 'Ujerumani' => 'Germany', 'United States of America' => 'United States'] as $name => $expected) {
            $payload = $this->payload();
            $payload['profile']['country'] = $name;
            $payload['profile']['email'] = 'country'.Str::random(8).'@example.test';
            $response = $this->sendApplication($payload)->assertCreated();
            $application = PartnerApplication::where('reference', $response->json('application.reference'))->firstOrFail();
            $this->assertSame($expected, $application->application_payload['profile']['country']);
        }
    }

    public function test_email_format_is_checked_before_saving_files_or_application(): void
    {
        foreach (['name@', 'name.com', 'name@example', 'name@@example.com', 'name..last@example.com', '.name@example.com', 'name.@example.com', 'name@example..com', 'name@-example.com', 'name@example-.com', 'first last@example.com', str_repeat('a', 65).'@example.com'] as $index => $email) {
            $payload = $this->payload();
            $payload['profile']['email'] = $email;
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
            $this->sendApplication($payload)->assertUnprocessable()->assertJsonValidationErrors('application.profile.email');
        }
        $this->assertSame(0, PartnerApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_email_case_and_outer_spaces_are_normalized_without_removing_plus_tags(): void
    {
        $payload = $this->payload();
        $payload['profile']['email'] = '  Asha+Travel@EXAMPLE.COM  ';
        $this->sendApplication($payload)->assertCreated();
        $record = PartnerApplication::sole();
        $this->assertSame('asha+travel@example.com', $record->application_payload['profile']['email']);
        $this->assertSame(\App\Support\PartnerIdentity::emailHash('asha+travel@example.com'), $record->email_hash);
    }

    public function test_each_document_can_be_exactly_ten_mb_in_a_multi_document_application(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'hotel';
        $payload['identity']['brelaNo'] = 'TEST-BRELA';
        $payload['identity']['tin'] = 'TEST-TIN';
        $documents = [];
        foreach (['id_front', 'id_back', 'registration', 'director_id'] as $kind) {
            $documents[$kind] = UploadedFile::fake()->create($kind.'.pdf', 10240, 'application/pdf');
        }
        $this->withHeader('Accept', 'application/json')->post('/api/public/partner-applications', [
            'submission_key' => (string) Str::uuid(), 'application' => json_encode($payload), 'documents' => $documents,
        ])->assertCreated();
        $this->assertCount(4, PartnerApplication::sole()->documents);
    }

    public function test_file_size_error_explains_the_ten_mb_limit(): void
    {
        $response = $this->withHeader('Accept', 'application/json')->post('/api/public/partner-applications', [
            'submission_key' => (string) Str::uuid(), 'application' => json_encode($this->payload()),
            'documents' => [
                'id_front' => UploadedFile::fake()->create('too-large.pdf', 10241, 'application/pdf'),
                'id_back' => UploadedFile::fake()->image('back.jpg', 700, 500),
                'selfie' => UploadedFile::fake()->image('selfie.jpg', 700, 500),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('documents.id_front');
        $this->assertSame('Each document must be 10 MB or smaller.', $response->json('errors')['documents.id_front'][0]);
        $this->assertSame(0, PartnerApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pending_accounts_require_different_emails_and_original_retry_stays_idempotent(): void
    {
        $key=(string)Str::uuid();$first=$this->sendApplication(key:$key)->assertCreated();$old=PartnerApplication::sole();$files=Storage::disk('local')->allFiles();
        $duplicate=$this->payload();$duplicate['profile']['email']=' APPLICANT@EXAMPLE.TEST ';
        $this->sendApplication($duplicate)->assertUnprocessable()->assertJsonValidationErrors('application.profile.email');
        $this->assertSame(1,PartnerApplication::count());$this->assertSame($files,Storage::disk('local')->allFiles());
        $this->sendApplication(key:$key)->assertOk()->assertJsonPath('application.reference',$first->json('application.reference'));
        $second=$this->payload();$second['profile']['email']='different@example.test';$this->sendApplication($second)->assertCreated();
        $this->assertSame(2,PartnerApplication::count());$this->assertSame('under_review',$old->fresh()->status);$this->assertSame(2,DB::table('partner_application_email_claims')->count());
    }

    public function test_existing_accounts_are_preserved_and_cannot_be_duplicated_even_without_a_claim(): void
    {
        $this->sendApplication()->assertCreated();$old=PartnerApplication::sole();$old->update(['status'=>'rejected']);DB::table('partner_application_email_claims')->delete();
        $this->sendApplication()->assertUnprocessable()->assertJsonValidationErrors('application.profile.email');$this->assertSame('rejected',$old->fresh()->status);
        $this->assertSame(1,PartnerApplication::count());
    }

    public function test_email_claim_migration_reserves_legacy_duplicates_without_deleting_them(): void
    {
        $this->sendApplication()->assertCreated();$original=PartnerApplication::sole();$duplicate=$original->replicate();$duplicate->submission_key=(string)Str::uuid();$duplicate->reference='TP-'.Str::uuid();$duplicate->save();
        $migration=require database_path('migrations/2026_11_14_000001_create_partner_application_email_claims.php');$migration->down();$migration->up();
        $this->assertSame(2,PartnerApplication::count());$this->assertSame(1,DB::table('partner_application_email_claims')->count());
        $this->sendApplication()->assertUnprocessable()->assertJsonValidationErrors('application.profile.email');
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
            'consent' => ['terms' => true, 'privacy' => true, 'dataProcessing' => true, 'accuracy' => true],
        ];
    }
}
