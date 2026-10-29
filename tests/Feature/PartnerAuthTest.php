<?php

namespace Tests\Feature;

use App\Mail\PartnerLoginCodeMail;
use App\Models\PartnerApplication;
use App\Support\PartnerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_25_000001_create_partner_applications_table.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_partner_authentication.php'))->up();
        (require database_path('migrations/2026_10_26_000002_add_partner_application_reviews.php'))->up();
        Mail::fake();
    }

    public function test_requests_email_code_and_stores_only_a_hash(): void
    {
        $this->application();
        [$challenge, $code] = $this->issueCode();
        $record = DB::table('partner_login_challenges')->where('id', $challenge)->first();
        $this->assertTrue(Hash::check($code, $record->code_hash));
        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(0, DB::table('partner_sessions')->count());
        Mail::assertSent(PartnerLoginCodeMail::class, fn ($mail) => $mail->hasTo('partner@example.test'));
    }

    public function test_unknown_email_receives_the_same_generic_response_without_email(): void
    {
        $this->postJson('/api/partner-auth/code', ['email' => 'unknown@example.test', 'language' => 'en'])
            ->assertStatus(202)->assertJsonStructure(['challenge_id', 'retry_after', 'expires_in'])
            ->assertJsonCount(3)->assertJsonPath('expires_in', 600);
        Mail::assertNothingSent();
        $this->assertNull(DB::table('partner_login_challenges')->first()->partner_application_id);
    }

    public function test_valid_code_creates_scoped_session_without_exposing_private_documents(): void
    {
        $application = $this->application('approved');
        [$challenge, $code] = $this->issueCode();
        $response = $this->verify($challenge, $code)->assertOk()->assertJsonPath('partner.status', 'approved');
        $token = $response->json('session_token');
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), DB::table('partner_sessions')->sole()->token_hash);
        $this->assertNotNull(DB::table('partner_login_challenges')->where('id', $challenge)->value('consumed_at'));
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertOk()
            ->assertJsonPath('partner.reference', $application->reference)
            ->assertJsonMissingPath('partner.identity')->assertJsonMissingPath('partner.payout')
            ->assertJsonMissingPath('partner.documents')->assertJsonMissingPath('partner.submission_key');
    }

    public function test_sign_in_does_not_approve_an_application(): void
    {
        $application = $this->application();
        [$challenge, $code] = $this->issueCode();
        $this->verify($challenge, $code)->assertOk()->assertJsonPath('partner.status', 'under_review');
        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_wrong_code_cannot_create_a_session_and_five_attempts_lock_the_challenge(): void
    {
        $this->application();
        [$challenge, $code] = $this->issueCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->verify($challenge, $wrong)->assertUnprocessable();
        }
        $this->verify($challenge, $code)->assertUnprocessable();
        $this->assertSame(0, DB::table('partner_sessions')->count());
    }

    public function test_expired_code_and_replayed_code_are_rejected(): void
    {
        $this->application();
        [$challenge, $code] = $this->issueCode();
        $this->verify($challenge, $code)->assertOk();
        $this->verify($challenge, $code)->assertUnprocessable();
        $this->travel(61)->seconds();
        [$next, $nextCode] = $this->issueCode();
        $this->travel(10)->minutes();
        $this->verify($next, $nextCode)->assertUnprocessable();
        $this->assertSame(1, DB::table('partner_sessions')->count());
    }

    public function test_resend_invalidates_old_challenge_and_respects_cooldown(): void
    {
        $this->application();
        [$old, $oldCode] = $this->issueCode();
        $this->postJson('/api/partner-auth/code', ['email' => 'partner@example.test'])->assertStatus(429);
        $this->travel(61)->seconds();
        [$new, $newCode] = $this->issueCode();
        $this->verify($old, $oldCode)->assertUnprocessable();
        $this->verify($new, $newCode)->assertOk();
    }

    public function test_session_expiry_and_invalid_tokens_are_rejected(): void
    {
        $this->application();
        [$challenge, $code] = $this->issueCode();
        $token = $this->verify($challenge, $code)->json('session_token');
        $this->withToken(Str::random(64))->getJson('/api/partner-auth/me')->assertUnauthorized();
        $this->travel(12)->hours();
        $this->withToken($token)->getJson('/api/partner-auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        $this->application();
        [$challenge, $code] = $this->issueCode();
        $first = $this->verify($challenge, $code)->json('session_token');
        $this->travel(61)->seconds();
        [$challenge, $code] = $this->issueCode();
        $second = $this->verify($challenge, $code)->json('session_token');
        $this->withToken($first)->postJson('/api/partner-auth/logout')->assertOk();
        $this->withToken($first)->getJson('/api/partner-auth/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/partner-auth/me')->assertOk();
    }

    public function test_missing_session_and_fake_preview_codes_are_rejected(): void
    {
        $this->getJson('/api/partner-auth/me')->assertUnauthorized();
        $this->postJson('/api/partner-auth/logout')->assertUnauthorized();
        $this->verify((string) Str::uuid(), '482913')->assertUnprocessable();
        $this->assertSame(0, DB::table('partner_sessions')->count());
    }

    public function test_email_matching_is_case_insensitive_and_latest_application_is_used(): void
    {
        $this->application('approved');
        $latest = $this->application();
        $response = $this->postJson('/api/partner-auth/code', ['email' => 'PARTNER@EXAMPLE.TEST', 'language' => 'sw'])->assertStatus(202);
        $mail = Mail::sent(PartnerLoginCodeMail::class)->last();
        $this->assertSame('sw', $mail->language);
        $this->verify($response->json('challenge_id'), $mail->code)->assertOk()->assertJsonPath('partner.reference', $latest->reference);
    }

    public function test_migration_backfills_existing_encrypted_email_addresses(): void
    {
        $migration = require database_path('migrations/2026_10_26_000001_add_partner_authentication.php');
        $migration->down();
        $application = $this->application(includeHash: false);
        $migration->up();
        $this->assertSame(PartnerIdentity::emailHash('partner@example.test'), $application->fresh()->email_hash);
    }

    public function test_invalid_inputs_and_changed_email_cannot_authenticate(): void
    {
        $this->postJson('/api/partner-auth/code', ['email' => 'invalid'])->assertUnprocessable();
        $application = $this->application();
        [$challenge, $code] = $this->issueCode();
        $application->update(['email_hash' => PartnerIdentity::emailHash('other@example.test')]);
        $this->verify($challenge, $code)->assertUnprocessable();
        $this->verify($challenge, '12345')->assertUnprocessable();
        $this->assertSame(0, DB::table('partner_sessions')->count());
    }

    public function test_email_failure_does_not_create_a_session_or_leave_a_valid_challenge(): void
    {
        $this->application();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Simulated provider failure'));
        $this->postJson('/api/partner-auth/code', ['email' => 'partner@example.test'])->assertStatus(202);
        $this->assertNotNull(DB::table('partner_login_challenges')->first()->consumed_at);
        $this->assertSame(0, DB::table('partner_sessions')->count());
    }

    private function issueCode(): array
    {
        $response = $this->postJson('/api/partner-auth/code', ['email' => 'partner@example.test', 'language' => 'en'])->assertStatus(202);
        $this->assertSame(['challenge_id', 'expires_in', 'retry_after'], array_keys($response->json()));
        return [$response->json('challenge_id'), Mail::sent(PartnerLoginCodeMail::class)->last()->code];
    }

    private function verify(string $challenge, string $code)
    {
        return $this->postJson('/api/partner-auth/verify', ['challenge_id' => $challenge, 'code' => $code]);
    }

    private function application(string $status = 'under_review', bool $includeHash = true): PartnerApplication
    {
        $data = [
            'submission_key' => (string) Str::uuid(),
            'reference' => 'TP-2026-'.Str::upper((string) Str::uuid()),
            'status' => $status,
            'application_payload' => [
                'type' => 'content_creator', 'language' => 'en',
                'profile' => ['displayName' => 'Partner', 'legalName' => 'Test Partner', 'email' => 'partner@example.test', 'country' => 'Tanzania', 'city' => 'Dar es Salaam', 'phone' => '+255712345678'],
                'identity' => ['idNumber' => 'PRIVATE-ID'], 'payout' => ['accountNumber' => 'PRIVATE-ACCOUNT'],
            ],
            'documents' => ['id_front' => 'private/id.jpg'],
            'submitted_at' => now(),
        ];
        if ($includeHash) $data['email_hash'] = PartnerIdentity::emailHash('partner@example.test');
        return PartnerApplication::create($data);
    }
}
