<?php

namespace Tests\Feature;

use App\Models\PartnerApplication;
use App\Models\PartnerReferral;
use App\Services\PartnerReferralService;
use App\Support\PartnerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Zxing\QrReader;

class PartnerReferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'partners.shop_url' => 'https://shop.example.test']);
        DB::purge('sqlite');
        foreach (['2026_10_25_000001_create_partner_applications_table', '2026_10_26_000001_add_partner_authentication', '2026_10_26_000002_add_partner_application_reviews', '2026_10_29_000001_create_partner_referrals'] as $migration) {
            (require database_path("migrations/$migration.php"))->up();
        }
    }

    public function test_share_and_qr_require_an_authenticated_approved_partner(): void
    {
        $this->getJson('/api/partner-auth/referral')->assertUnauthorized();
        $this->getJson('/api/partner-auth/referral/qr')->assertUnauthorized();
        foreach (['under_review', 'info_requested', 'rejected', 'suspended'] as $status) {
            $this->withToken($this->token($this->application($status)))->getJson('/api/partner-auth/referral')->assertForbidden();
        }
        $this->assertSame(0, PartnerReferral::count());
    }

    public function test_code_is_unique_and_stable_when_a_partners_name_changes(): void
    {
        $application = $this->application('approved');
        $token = $this->token($application);
        $first = $this->withToken($token)->getJson('/api/partner-auth/referral')->assertOk();
        $code = $first->json('referral.code');
        $this->assertMatchesRegularExpression('/^TRV-[A-Z0-9]{8}$/', $code);
        $application->update(['application_payload' => [...$application->application_payload, 'profile' => [...$application->application_payload['profile'], 'displayName' => 'Changed name']]]);
        $this->withToken($token)->getJson('/api/partner-auth/referral')->assertOk()->assertJsonPath('referral.code', $code);
        $second = app(PartnerReferralService::class)->ensureFor($this->application('approved'));
        $this->assertNotSame($code, $second->code);
        $this->assertSame(2, PartnerReferral::count());
    }

    public function test_public_lookup_reveals_only_active_code(): void
    {
        $application = $this->application('approved');
        $referral = app(PartnerReferralService::class)->ensureFor($application);
        $this->getJson('/api/public/partner-referrals/'.strtolower($referral->code))->assertOk()->assertExactJson(['referral' => ['code' => $referral->code]]);
        $application->update(['status' => 'suspended']);
        $this->getJson('/api/public/partner-referrals/'.$referral->code)->assertNotFound();
        $this->getJson('/api/public/partner-referrals/TRV-XXXXXXXX')->assertNotFound();
    }

    public function test_png_qr_decodes_to_the_actual_referral_link(): void
    {
        $application = $this->application('approved');
        $token = $this->token($application);
        $code = $this->withToken($token)->getJson('/api/partner-auth/referral')->json('referral.code');
        $response = $this->withToken($token)->get('/api/partner-auth/referral/qr')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
        $file = tempnam(sys_get_temp_dir(), 'travela-qr-');
        try {
            file_put_contents($file, $response->getContent());
            $this->assertSame('https://shop.example.test/r/'.$code, (new QrReader($file, QrReader::SOURCE_TYPE_FILE, false))->text());
        } finally { unlink($file); }
    }

    public function test_migration_assigns_codes_to_existing_approved_applications_only(): void
    {
        $migration = require database_path('migrations/2026_10_29_000001_create_partner_referrals.php');
        $migration->down();
        $approved = $this->application('approved');
        $this->application('under_review');
        $migration->up();
        $this->assertSame($approved->id, PartnerReferral::sole()->partner_application_id);
    }

    public function test_random_collision_is_retried_without_changing_existing_codes(): void
    {
        $first = $this->application('approved');
        PartnerReferral::create(['partner_application_id' => $first->id, 'code' => 'TRV-AAAAAAAA']);
        $second = $this->application('approved');
        $attempt = 0;
        Str::createRandomStringsUsing(function () use (&$attempt) { return ++$attempt === 1 ? 'AAAAAAAA' : 'BBBBBBBB'; });
        try { $this->assertSame('TRV-BBBBBBBB', app(PartnerReferralService::class)->ensureFor($second)->code); }
        finally { Str::createRandomStringsNormally(); }
        $this->assertSame('TRV-AAAAAAAA', PartnerReferral::where('partner_application_id', $first->id)->sole()->code);
    }

    private function token(PartnerApplication $application): string
    {
        $token = Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id' => $application->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }

    private function application(string $status): PartnerApplication
    {
        return PartnerApplication::create([
            'submission_key' => (string) Str::uuid(), 'reference' => 'TP-2026-'.Str::upper((string) Str::uuid()), 'status' => $status,
            'email_hash' => PartnerIdentity::emailHash('partner@example.test'),
            'application_payload' => ['type' => 'content_creator', 'language' => 'en', 'profile' => ['displayName' => 'Test creator', 'legalName' => 'Test creator', 'email' => 'partner@example.test', 'country' => 'Tanzania', 'city' => 'Dar es Salaam', 'phone' => '+255700000000']],
            'documents' => [], 'submitted_at' => now(),
        ]);
    }
}
