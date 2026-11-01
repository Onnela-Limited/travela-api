<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PartnerApplication;
use App\Services\PartnerReferralService;
use App\Services\PartnerSalesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerSalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (['2026_10_25_000001_create_partner_applications_table', '2026_10_26_000001_add_partner_authentication', '2026_10_29_000001_create_partner_referrals'] as $migration) {
            (require database_path("migrations/$migration.php"))->up();
        }
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('partner_referral_id')->nullable();
            $t->string('status'); $t->string('payment_status')->nullable();
            $t->decimal('total_amount', 12, 2); $t->string('currency');
            $t->json('metadata')->nullable(); $t->json('payment_callback')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_31_000001_add_partner_commission_to_orders.php'))->up();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_only_approved_authenticated_partner_can_read_sales(): void
    {
        $this->getJson('/api/partner-auth/dashboard')->assertUnauthorized();
        $this->getJson('/api/partner-auth/sales')->assertUnauthorized();
        foreach (['under_review', 'rejected', 'info_requested', 'suspended'] as $status) {
            $this->withToken($this->token($this->partner($status)))->getJson('/api/partner-auth/dashboard')->assertForbidden();
            $this->getJson('/api/partner-auth/sales')->assertForbidden();
        }
    }

    public function test_empty_dashboard_is_real_zero_not_sample_data(): void
    {
        $this->withToken($this->token($this->partner()))->getJson('/api/partner-auth/dashboard')->assertOk()
            ->assertJsonPath('dashboard.totals.0.earnedMonth', '0.00')->assertJsonPath('dashboard.totals.0.sales30', 0)
            ->assertJsonCount(30, 'dashboard.daily')->assertJsonCount(0, 'dashboard.latest');
    }

    public function test_totals_use_paid_date_net_amount_and_integer_rounding(): void
    {
        $partner = $this->partner();
        $this->order($partner, ['total_amount' => '19.95', 'partner_commission_bps' => 1000]);
        $this->order($partner, ['total_amount' => '10.00', 'partner_commission_bps' => 1200, 'created_at' => '2026-09-01 12:00:00']);
        $this->order($partner, ['total_amount' => '20.00', 'paid_at' => '2025-10-31 12:00:00']);
        $this->order($partner, ['total_amount' => '30.00', 'payment_status' => 'pending', 'status' => 'pending_payment', 'paid_at' => null]);
        $this->withToken($this->token($partner))->getJson('/api/partner-auth/dashboard')->assertOk()
            ->assertJsonPath('dashboard.totals.0.sales30', 2)->assertJsonPath('dashboard.totals.0.revenue30', '29.95')
            ->assertJsonPath('dashboard.totals.0.earnedMonth', '3.20')->assertJsonPath('dashboard.totals.0.earnedAllTime', '5.20')
            ->assertJsonPath('dashboard.totals.0.pending', '3.00')->assertJsonPath('dashboard.daily.29.commission', '3.20');
    }

    public function test_dar_es_salaam_midnight_and_month_boundaries(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-30 22:00:00', 'UTC'));
        $partner = $this->partner();
        $this->order($partner, ['paid_at' => '2026-09-30 21:30:00', 'total_amount' => '10.00']);
        $this->order($partner, ['paid_at' => '2026-09-30 20:59:00', 'total_amount' => '20.00']);
        $this->withToken($this->token($partner))->getJson('/api/partner-auth/dashboard')->assertOk()
            ->assertJsonPath('dashboard.totals.0.earnedMonth', '1.00')->assertJsonPath('dashboard.daily.29.commission', '1.00')
            ->assertJsonPath('dashboard.daily.28.commission', '2.00');
    }

    public function test_failed_refunded_legacy_and_held_orders_are_not_earnings(): void
    {
        $partner = $this->partner();
        foreach (['failed', 'refunded', 'reversed', 'cancelled'] as $status) $this->order($partner, ['payment_status' => $status]);
        $this->order($partner, ['partner_commission_bps' => null]);
        $this->order($partner, ['metadata' => []]);
        $this->order($partner, ['paid_at' => null]);
        foreach (['payment.refunded', 'payment.on_hold', 'payment.reversed'] as $event) {
            $this->order($partner, ['payment_callback' => ['deliveries' => [['event' => $event]], 'last' => ['event' => 'payment.completed']]]);
        }
        $this->withToken($this->token($partner))->getJson('/api/partner-auth/dashboard')->assertOk()
            ->assertJsonPath('dashboard.totals.0.earnedAllTime', '0.00')->assertJsonPath('dashboard.totals.0.pending', '0.00')
            ->assertJsonPath('dashboard.reviewCount', 6);
    }

    public function test_partner_isolation_pagination_and_no_customer_details(): void
    {
        $partner = $this->partner();
        $other = $this->order($this->partner(), ['total_amount' => '999.00']);
        for ($i = 0; $i < 23; $i++) $this->order($partner);
        $this->withToken($this->token($partner))->getJson('/api/partner-auth/sales?page=1')->assertOk()
            ->assertJsonCount(20, 'sales')->assertJsonPath('pagination.total', 23)->assertJsonPath('pagination.lastPage', 2);
        $response = $this->getJson('/api/partner-auth/sales?page=2')->assertOk()->assertJsonCount(3, 'sales');
        $this->assertSame(['id', 'date', 'currency', 'amount', 'commission', 'rateBps', 'state'], array_keys($response->json('sales.0')));
        $this->assertNotContains((string) $other->id, array_column($response->json('sales'), 'id'));
        $this->getJson('/api/partner-auth/sales?page=-1')->assertUnprocessable();
        $this->getJson('/api/partner-auth/sales?page=100001')->assertUnprocessable();
    }

    public function test_rates_come_from_server_and_unknown_contracts_need_review(): void
    {
        $service = app(PartnerSalesService::class);
        foreach (['content_creator' => 1000, 'tour_guide' => 1000, 'hotel' => 1200, 'tour_operator' => 1200, 'event_organiser' => 1200, 'airline' => null, 'unknown' => null] as $type => $rate) {
            $partner = $this->partner();
            $partner->application_payload = ['type' => $type];
            $this->assertSame($rate, $service->rate($partner));
        }
        $partner = $this->partner();
        $this->order($partner, ['currency' => 'EUR']);
        $this->withToken($this->token($partner))->getJson('/api/partner-auth/dashboard')->assertOk()
            ->assertJsonPath('dashboard.reviewCount', 1)->assertJsonPath('dashboard.totals.0.currency', 'EUR')
            ->assertJsonPath('dashboard.totals.0.earnedAllTime', '0.00')->assertJsonPath('dashboard.totals.1.currency', 'USD');
    }

    private function partner(string $status = 'approved'): PartnerApplication
    {
        return PartnerApplication::create(['submission_key' => (string) Str::uuid(), 'reference' => 'TP-'.Str::uuid(),
            'status' => $status, 'application_payload' => ['type' => 'content_creator'], 'documents' => [], 'submitted_at' => now()]);
    }

    private function token(PartnerApplication $partner): string
    {
        $token = Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id' => $partner->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }

    private function order(PartnerApplication $partner, array $overrides = []): Order
    {
        return Order::create(array_merge(['partner_referral_id' => app(PartnerReferralService::class)->ensureFor($partner)->id,
            'partner_commission_bps' => 1000, 'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(),
            'total_amount' => '50.00', 'currency' => 'USD', 'metadata' => ['pricing_version' => 1]], $overrides));
    }
}
