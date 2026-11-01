<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PartnerApplication;
use App\Models\PartnerPayout;
use App\Models\User;
use App\Services\PartnerPayoutService;
use App\Services\PartnerReferralService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerPayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests(); Mail::fake();
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('user'));
        (require database_path('migrations/2025_10_05_114523_create_personal_access_tokens_table.php'))->up();
        foreach (['2026_10_25_000001_create_partner_applications_table', '2026_10_26_000001_add_partner_authentication', '2026_10_29_000001_create_partner_referrals'] as $migration) {
            (require database_path("migrations/$migration.php"))->up();
        }
        (require database_path('migrations/2026_11_01_000002_create_partner_settings.php'))->up();
        (require database_path('migrations/2026_11_01_000001_create_partner_teams.php'))->up();
        (require database_path('migrations/2026_11_01_000003_create_partner_notifications.php'))->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('partner_referral_id')->nullable(); $t->string('status'); $t->string('payment_status')->nullable();
            $t->decimal('total_amount', 12, 2); $t->string('currency'); $t->json('metadata')->nullable(); $t->json('payment_callback')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_31_000001_add_partner_commission_to_orders.php'))->up();
        (require database_path('migrations/2026_10_31_000002_create_partner_payouts.php'))->up();
    }

    public function test_anonymous_and_non_admin_users_cannot_access_payout_admin(): void
    {
        $this->getJson('/api/partner-auth/payouts')->assertUnauthorized();
        $this->postJson('/api/partner-auth/payouts', [])->assertUnauthorized();
        $this->getJson('/api/admin/partner-payouts')->assertUnauthorized();
        $partner = $this->partner(); $payout = $this->requestPayout($partner);
        // A partner session is never an admin token.
        $this->getJson('/api/admin/partner-payouts/'.$payout->id)->assertUnauthorized();
        $this->postJson('/api/admin/partner-payouts/'.$payout->id.'/review', [])->assertUnauthorized();
        foreach (['agent', 'user'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/admin/partner-payouts')->assertForbidden();
            $this->getJson('/api/admin/partner-payouts/'.$payout->id)->assertForbidden();
            $this->postJson('/api/admin/partner-payouts/'.$payout->id.'/review', [])->assertForbidden();
        }
    }

    public function test_only_approved_active_sessions_can_read_and_request(): void
    {
        foreach (['under_review','rejected','info_requested','suspended'] as $status) {
            $p = $this->partner($status); $this->asPartner($p);
            $this->getJson('/api/partner-auth/payouts')->assertForbidden();
            $this->postJson('/api/partner-auth/payouts', $this->payload())->assertForbidden();
        }
        $p = $this->partner(); $this->asPartner($p);
        DB::table('partner_sessions')->where('partner_application_id', $p->id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/partner-auth/payouts')->assertUnauthorized();
        $this->assertDatabaseCount('partner_payouts', 0);
    }

    public function test_eligible_balance_excludes_unpaid_legacy_foreign_and_refunded_orders(): void
    {
        $p = $this->partner();
        $this->order($p, ['total_amount' => '199.95']);
        foreach ([['payment_status' => 'pending'], ['payment_status' => 'refunded'], ['partner_commission_bps' => null], ['metadata' => []], ['currency' => 'TZS'], ['paid_at' => now()->addDay()], ['payment_callback' => ['last' => ['event' => 'payment.reversed']]]] as $patch) $this->order($p, $patch);
        $this->asPartner($p);
        $this->getJson('/api/partner-auth/payouts')->assertOk()->assertJsonPath('balances.available', '20.00')->assertJsonPath('balances.reserved', '0.00')->assertJsonPath('minimum', '10.00');
        $this->postJson('/api/partner-auth/payouts', $this->payload('20.00'))->assertOk()->assertJsonPath('payout.amount', '20.00');
        $this->getJson('/api/partner-auth/payouts')->assertJsonPath('balances.available', '0.00')->assertJsonPath('balances.reserved', '20.00');
    }

    public function test_request_replays_are_idempotent_and_only_one_active_request_is_allowed(): void
    {
        $p = $this->partner(); $this->order($p); $this->asPartner($p);
        $payload = $this->payload();
        $first = $this->postJson('/api/partner-auth/payouts', $payload)->assertOk()->json('payout.reference');
        $this->postJson('/api/partner-auth/payouts', $payload)->assertOk()->assertJsonPath('payout.reference', $first);
        $this->postJson('/api/partner-auth/payouts', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('partner_payouts', 1);
        $this->assertDatabaseCount('partner_payout_events', 0);
    }

    public function test_server_ignores_forged_destination_owner_amount_and_status(): void
    {
        $p = $this->partner(); $this->order($p); $this->asPartner($p);
        $payload = [...$this->payload(), 'amount_cents' => 999999, 'partner_application_id' => 999, 'status' => 'paid', 'destination' => ['accountNumber' => 'ATTACKER']];
        $this->postJson('/api/partner-auth/payouts', $payload)->assertOk()->assertJsonPath('payout.amount', '10.00')->assertJsonPath('payout.status', 'pending')->assertDontSee('PRIVATE-ACCOUNT-1234');
        $payout = PartnerPayout::sole();
        $this->assertSame($p->id, $payout->partner_application_id);
        $this->assertSame('PRIVATE-ACCOUNT-1234', $payout->destination['accountNumber']);
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT', DB::table('partner_payouts')->value('destination'));
    }

    public function test_stale_balance_missing_consent_and_below_minimum_are_blocked(): void
    {
        $p = $this->partner(); $this->asPartner($p);
        $this->postJson('/api/partner-auth/payouts', $this->payload('0.00'))->assertUnprocessable();
        $this->order($p);
        $this->postJson('/api/partner-auth/payouts', $this->payload('9.00'))->assertUnprocessable();
        $this->postJson('/api/partner-auth/payouts', [...$this->payload(), 'destination_confirmed' => false])->assertUnprocessable();
        $this->assertDatabaseCount('partner_payouts', 0);
    }

    public function test_incomplete_destination_does_not_break_history_but_blocks_request(): void
    {
        $p = $this->partner(); $this->order($p); $this->asPartner($p);
        $p->update(['application_payload' => ['type' => 'content_creator']]);
        $this->getJson('/api/partner-auth/payouts')->assertOk()->assertJsonPath('destination', null);
        $this->postJson('/api/partner-auth/payouts', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('partner_payouts', 0);
    }

    public function test_destination_snapshot_is_immutable_and_full_number_is_admin_only(): void
    {
        $p = $this->partner(); $payout = $this->requestPayout($p);
        $p->update(['application_payload' => ['payout' => ['accountNumber' => 'CHANGED']]]);
        $this->getJson('/api/partner-auth/payouts')->assertOk()->assertJsonPath('payouts.0.destination.accountMasked', '•••• 1234')->assertDontSee('PRIVATE-ACCOUNT-1234')->assertJsonMissingPath('payouts.0.request_key');
        $this->admin();
        $this->getJson('/api/admin/partner-payouts')->assertOk()->assertDontSee('PRIVATE-ACCOUNT-1234');
        $this->getJson('/api/admin/partner-payouts/'.$payout->id)->assertOk()->assertJsonPath('payout.fullDestination.accountNumber', 'PRIVATE-ACCOUNT-1234');
    }

    public function test_claim_then_record_payment_updates_balances_and_immutable_history(): void
    {
        $p = $this->partner(); $payout = $this->requestPayout($p); $this->admin();
        $this->review($payout, 'paid', 0, $this->transfer())->assertConflict();
        $this->review($payout, 'processing', 0)->assertOk();
        $this->review($payout, 'paid', 1, $this->transfer())->assertOk();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->review($payout, 'rejected', 2, ['message' => 'Cannot undo paid', 'no_transfer_confirmed' => true])->assertConflict();
        $this->getJson('/api/admin/partner-payouts/'.$payout->id)->assertOk()->assertJsonCount(2, 'payout.history')->assertJsonPath('payout.paymentReference', 'BANK-REF-001');
        $this->asPartner($p);
        $this->getJson('/api/partner-auth/payouts')->assertJsonPath('balances.paid', '10.00')->assertJsonPath('balances.reserved', '0.00')->assertJsonPath('balances.available', '0.00');
        $this->postJson('/api/partner-auth/payouts', $this->payload('0.00'))->assertUnprocessable();
        Http::assertNothingSent(); Mail::assertNothingSent();
    }

    public function test_rejection_requires_reason_and_no_transfer_confirmation_and_releases_balance(): void
    {
        $p = $this->partner(); $payout = $this->requestPayout($p); $this->admin();
        $this->review($payout, 'rejected', 0)->assertUnprocessable();
        $this->review($payout, 'rejected', 0, ['message' => 'PRIVATE-REASON'])->assertUnprocessable();
        $this->review($payout, 'rejected', 0, ['message' => 'PRIVATE-REASON', 'no_transfer_confirmed' => true])->assertOk();
        $this->assertStringNotContainsString('PRIVATE-REASON', DB::table('partner_payout_events')->value('message'));
        $this->asPartner($p);
        $this->getJson('/api/partner-auth/payouts')->assertJsonPath('balances.available', '10.00')->assertJsonPath('payouts.0.message', 'PRIVATE-REASON');
        $this->postJson('/api/partner-auth/payouts', $this->payload())->assertOk();
        $this->assertDatabaseCount('partner_payouts', 2);
    }

    public function test_only_claiming_admin_can_complete_or_release_processing_request(): void
    {
        $payout = $this->requestPayout($this->partner()); $first = $this->admin();
        $this->review($payout, 'processing', 0)->assertOk();
        $this->admin();
        $this->review($payout, 'processing', 0)->assertConflict();
        $this->review($payout, 'paid', 1, $this->transfer())->assertConflict();
        $this->review($payout, 'rejected', 1, ['message' => 'No payment', 'no_transfer_confirmed' => true])->assertConflict();
        Sanctum::actingAs($first);
        $this->review($payout, 'paid', 1, $this->transfer())->assertOk();
    }

    public function test_recording_requires_positive_amount_currency_confirmation_and_unique_reference(): void
    {
        $first = $this->requestPayout($this->partner());
        $second = $this->requestPayout($this->partner()); $this->admin();
        $this->review($first, 'processing', 0)->assertOk();
        $this->review($first, 'paid', 1, [...$this->transfer(), 'transfer_confirmed' => false])->assertUnprocessable();
        $this->review($first, 'paid', 1, [...$this->transfer(), 'transfer_amount' => '9.00'])->assertUnprocessable();
        $this->review($first, 'paid', 1, [...$this->transfer(), 'transfer_amount' => '0.00'])->assertUnprocessable();
        $this->review($first, 'paid', 1, [...$this->transfer(), 'transfer_currency' => 'EUR'])->assertUnprocessable();
        $this->review($first, 'paid', 1, $this->transfer())->assertOk();
        $this->review($second, 'processing', 0)->assertOk();
        $this->review($second, 'paid', 1, [...$this->transfer(), 'payment_reference' => 'bank-ref-001'])->assertUnprocessable();
        $this->assertSame('processing', $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->version);
        $this->assertSame(3, DB::table('partner_payout_events')->count());
    }

    public function test_tzs_settlement_records_actual_amount_without_automatic_conversion(): void
    {
        $payout = $this->requestPayout($this->partner()); $this->admin();
        $this->review($payout, 'processing', 0)->assertOk();
        $this->review($payout, 'paid', 1, [...$this->transfer(), 'transfer_currency' => 'TZS', 'transfer_amount' => '26000.00'])->assertOk();
        $this->assertSame(1000, $payout->fresh()->amount_cents);
        $this->assertSame(2600000, $payout->fresh()->transfer_amount_cents);
    }

    public function test_refund_before_claim_blocks_payment_and_after_claim_can_be_recorded_as_shortfall(): void
    {
        $p = $this->partner(); $payout = $this->requestPayout($p); $this->admin();
        Order::query()->update(['payment_status' => 'refunded']);
        $this->review($payout, 'processing', 0)->assertUnprocessable();
        Order::query()->update(['payment_status' => 'paid']);
        $this->review($payout, 'processing', 0)->assertOk();
        Order::query()->update(['payment_status' => 'refunded']);
        // The app must still record a transfer that already happened externally.
        $this->review($payout, 'paid', 1, $this->transfer())->assertOk();
        $this->asPartner($p);
        $this->getJson('/api/partner-auth/payouts')->assertJsonPath('balances.available', '0.00')->assertJsonPath('balances.shortfall', '10.00');
    }

    public function test_partner_history_is_scoped_paginated_and_cannot_change_status(): void
    {
        $other = $this->requestPayout($this->partner());
        $p = $this->partner(); $this->asPartner($p);
        $this->getJson('/api/partner-auth/payouts?partner_application_id='.$other->partner_application_id)->assertOk()->assertJsonCount(0, 'payouts')->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/partner-auth/payouts?page=0')->assertUnprocessable();
        $this->getJson('/api/partner-auth/payouts?page=100001')->assertUnprocessable();
        $this->getJson('/api/partner-auth/payouts/'.$other->id)->assertNotFound();
        $this->postJson('/api/partner-auth/payouts/'.$other->id.'/review', [])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_payment_status_and_balance_changes(): void
    {
        $payout = $this->requestPayout($this->partner()); $this->admin();
        $this->review($payout, 'processing', 0)->assertOk();
        Schema::drop('partner_payout_events');
        $this->withoutExceptionHandling();
        try { app(PartnerPayoutService::class)->decide($payout->id, auth()->user(), ['decision' => 'paid', 'expected_version' => 1, ...$this->transfer()]); $this->fail('Expected audit failure'); }
        catch (\Illuminate\Database\QueryException) {
            $this->assertSame('processing', $payout->fresh()->status);
            $this->assertNull($payout->fresh()->payment_reference);
            $this->assertSame(1, $payout->fresh()->version);
        }
    }

    public function test_settings_changes_preserve_pending_destinations_and_require_fresh_confirmation(): void
    {
        $p=$this->partner();$payload=$p->application_payload;
        $payload['profile']=['displayName'=>'Partner','legalName'=>'Legal','email'=>'owner@example.test','phone'=>'+255712345678','city'=>'Dar','country'=>'TZ'];$p->update(['application_payload'=>$payload]);
        $old=$this->requestPayout($p);$body=['expected_version'=>0,'profile'=>array_diff_key($payload['profile'],array_flip(['legalName','email'])),
            'payout'=>['method'=>'bank','accountName'=>'Legal','accountNumber'=>'NEW-DESTINATION','bank'=>'Example Bank','branch'=>'','nameConfirmed'=>true]];
        $this->postJson('/api/partner-auth/settings',$body)->assertOk();
        $this->assertSame('PRIVATE-ACCOUNT-1234',$old->fresh()->destination['accountNumber']);
        // Retrying an already-saved request remains idempotent even after a settings change.
        $this->postJson('/api/partner-auth/payouts',['request_key'=>$old->request_key,'expected_amount'=>'10.00','destination_confirmed'=>true])->assertOk()->assertJsonPath('payout.id',(string)$old->id);
        $old->update(['status'=>'rejected']);
        $this->postJson('/api/partner-auth/payouts',$this->payload())->assertConflict();
        $this->postJson('/api/partner-auth/payouts',[...$this->payload(),'expected_settings_version'=>1])->assertOk();
        $this->assertSame('NEW-DESTINATION',PartnerPayout::latest('id')->first()->destination['accountNumber']);
    }

    public function test_payout_processing_and_paid_decisions_record_owner_updates(): void
    {
        $payout=$this->requestPayout($this->partner());$this->admin();
        $this->review($payout,'processing',0)->assertOk();$this->review($payout,'paid',1,$this->transfer())->assertOk();
        $this->assertSame(2,DB::table('partner_notifications')->where('category','payout')->count());
        $this->assertSame(0,DB::table('partner_notifications')->whereNotNull('partner_team_member_id')->count());
    }

    private function payload(string $amount = '10.00'): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_amount' => $amount, 'destination_confirmed' => true];
    }
    private function transfer(): array
    {
        return ['payment_reference' => 'BANK-REF-001', 'transfer_currency' => 'USD', 'transfer_amount' => '10.00', 'transfer_confirmed' => true];
    }
    private function review(PartnerPayout $p, string $decision, int $version, array $extra = [])
    {
        return $this->postJson('/api/admin/partner-payouts/'.$p->id.'/review', ['decision' => $decision, 'expected_version' => $version, ...$extra]);
    }
    private function requestPayout(PartnerApplication $p): PartnerPayout
    {
        $this->order($p); $this->asPartner($p);
        $id = $this->postJson('/api/partner-auth/payouts', $this->payload())->assertOk()->json('payout.id');
        return PartnerPayout::findOrFail($id);
    }
    private function partner(string $status = 'approved'): PartnerApplication
    {
        return PartnerApplication::create(['submission_key' => (string) Str::uuid(), 'reference' => 'TP-'.Str::uuid(), 'status' => $status,
            'application_payload' => ['type' => 'content_creator', 'profile' => ['displayName' => 'Test partner'],
                'payout' => ['method' => 'mpesa', 'accountName' => 'Test partner', 'accountNumber' => 'PRIVATE-ACCOUNT-1234', 'bank' => '', 'branch' => '', 'nameConfirmed' => true]],
            'documents' => [], 'submitted_at' => now()]);
    }
    private function asPartner(PartnerApplication $p): void
    {
        $token = Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id' => $p->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token);
    }
    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']); Sanctum::actingAs($user); return $user;
    }
    private function order(PartnerApplication $p, array $overrides = []): Order
    {
        return Order::create([...['partner_referral_id' => app(PartnerReferralService::class)->ensureFor($p)->id, 'partner_commission_bps' => 1000,
            'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(), 'total_amount' => '100.00', 'currency' => 'USD', 'metadata' => ['pricing_version' => 1]], ...$overrides]);
    }
}
