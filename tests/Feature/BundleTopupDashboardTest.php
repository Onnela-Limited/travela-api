<?php

namespace Tests\Feature;

use App\Mail\EsimRechargeConfirmationMail;
use App\Models\{Bundle, BundleType, Country, CountryProvider, Esim, Order, OrderItem, Provider, User, UserEsim};
use App\Services\Esim\{BundleHistoryService, OrderRechargeService, VodacomBalanceService, VodacomSimManagerService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Mail};
use Tests\TestCase;

class BundleTopupDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        if (config('database.default') !== 'sqlite') return [];
        $mysqlOnly = ['2026_07_25_000001_add_skipped_status_to_esim_import_items_table.php',
            '2026_07_25_000002_add_pending_provider_status_to_esims_table.php'];
        return ['--realpath' => true, '--path' => array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => ! in_array(basename($path), $mysqlOnly, true)))];
    }

    public function test_admin_topup_preserves_old_bundle_and_dashboard_uses_combined_carrier_balance(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->original($user, $sim, $bundle);
        $this->provider(['status' => 'SUCCESS']);
        $this->postJson('/api/admin/customers/'.$user->id.'/bundle-recharges', [
            'user_esim_id' => $sim->id, 'bundle_id' => $bundle->id, 'reference' => 'RECHARGE-added',
        ])->assertOk()->assertJsonPath('status', 'success');
        $this->balanceCallback(54 * 1024);
        $this->actingAs($user);
        $this->getJson('/api/me/esims')->assertOk()
            ->assertJsonPath('data.0.balances.DATA', 54 * 1024)
            ->assertJsonPath('data.0.active_bundles_data_mb', 54 * 1024)
            ->assertJsonCount(2, 'data.0.bundle_history');
        $history = app(BundleHistoryService::class)->forAssignment($sim->fresh())['bundle_history'];
        $this->assertEqualsCanonicalizing([24 * 1024, 30 * 1024], array_column($history, 'data_mb'));
        $this->assertNotSame($history[0]['expires_at'], $history[1]['expires_at']);
        Mail::assertSent(EsimRechargeConfirmationMail::class, fn ($mail) =>
            $mail->hasTo($user->email) && str_contains($mail->dashboardUrl, 'await_balance=1') &&
            str_contains($mail->dashboardUrl, 'msisdn=255797053059') && str_contains($mail->dashboardUrl, 'recharge=RECHARGE-added'));
        $this->assertDatabaseHas('customer_bundle_recharges', ['data_mb' => 30 * 1024]);
    }

    public function test_used_data_is_not_added_again_and_expired_bundle_keeps_its_original_expiry(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $old = $this->original($user, $sim, $bundle, 31);
        $this->provider(['status' => 'SUCCESS']);
        $this->postJson('/api/admin/customers/'.$user->id.'/bundle-recharges', [
            'user_esim_id' => $sim->id, 'bundle_id' => $bundle->id, 'reference' => 'RECHARGE-added',
        ])->assertOk();
        $this->balanceCallback(29 * 1024);
        $this->actingAs($user);
        $data = $this->getJson('/api/me/esims')->assertOk()->json('data.0');
        $this->assertSame(29 * 1024, $data['balances']['DATA']);
        $this->assertSame(30 * 1024, $data['active_bundles_data_mb']);
        $expired = collect($data['bundle_history'])->firstWhere('source', 'purchase');
        $this->assertTrue($expired['expired']);
        $this->assertSame($old->recharge_completed_at->copy()->addDays(30)->toIso8601String(), $expired['expires_at']);
        $this->assertFalse($data['plan_expired']);
        $this->assertSame($old->id, $sim->fresh()->order_id);
    }

    public function test_paid_self_topup_sends_one_email_and_is_added_alongside_original_bundle(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->original($user, $sim, $bundle);
        $order = $this->topup($user, $sim, $bundle);
        $this->provider(['status' => 'SUCCESS'], false);
        app(OrderRechargeService::class)->fulfillOrder($order);
        app(OrderRechargeService::class)->fulfillOrder($order->fresh());
        $order->refresh();
        $this->assertSame('success', $order->recharge_status);
        $this->assertSame('sent', data_get($order->metadata, 'topup_notification.status'));
        $state = app(BundleHistoryService::class)->forAssignment($sim->fresh());
        $this->assertSame(54 * 1024, $state['active_bundles_data_mb']);
        $this->assertCount(2, $state['bundle_history']);
        Mail::assertSentCount(1);
        Mail::assertSent(EsimRechargeConfirmationMail::class, fn ($mail) =>
            $mail->hasTo($user->email) && str_contains($mail->dashboardUrl, 'await_balance=1'));
    }

    public function test_queued_self_topup_waits_for_callback_before_history_and_email(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->original($user, $sim, $bundle);
        $order = $this->topup($user, $sim, $bundle);
        $this->provider(['status' => 'queued'], false, 202);
        app(OrderRechargeService::class)->fulfillOrder($order);
        $this->assertCount(1, app(BundleHistoryService::class)->forAssignment($sim->fresh())['bundle_history']);
        Mail::assertNothingSent();
        $payload = ['msisdn' => '255797053059', 'reference' => $order->fresh()->recharge_reference,
            'product_id' => 66, 'status' => 'SUCCEEDED'];
        $this->postJson('/api/public/my-recharge-callback-url', $payload)->assertOk();
        $this->postJson('/api/public/my-recharge-callback-url', $payload)->assertOk();
        $this->assertCount(2, app(BundleHistoryService::class)->forAssignment($sim->fresh())['bundle_history']);
        Mail::assertSentCount(1);
    }

    public function test_email_return_requests_fresh_balance_and_waits_for_data_not_just_airtime(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $sim->update(['balances' => ['DATA' => null, 'AIRTIME' => 100], 'balance_fetched_at' => now()]);
        $this->actingAs($user);
        $since = now()->subSecond()->toIso8601String();
        $url = '/api/me/esims/balance-status?'.http_build_query(['since' => $since, 'msisdn' => '255797053059', 'require_data' => 1]);
        $this->getJson($url)->assertOk()->assertJsonPath('balance_ready', false);
        $this->balanceCallback(54 * 1024);
        $this->getJson($url)->assertOk()->assertJsonPath('balance_ready', true)->assertJsonPath('data.balances.DATA', 54 * 1024);
        $other = User::factory()->create(['role' => 'user']);
        $this->actingAs($other);
        $this->getJson($url)->assertNotFound();
    }

    private function fixture(): array
    {
        Mail::fake();
        Cache::flush();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'user']);
        $esim = Esim::create(['msisdn' => '255797053059', 'network_id' => 1, 'status' => 'MANAGED', 'sim_type' => 'physical']);
        $sim = UserEsim::create(['user_id' => $user->id, 'esim_id' => $esim->id]);
        $type = BundleType::firstOrCreate(['code' => 'DATA'], ['name' => 'Data']);
        $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA']);
        $provider = Provider::create(['name' => 'Vodacom', 'slug' => 'vodacom']);
        $pivot = CountryProvider::create(['country_id' => $country->id, 'provider_id' => $provider->id, 'is_default' => true]);
        $bundle = Bundle::create(['bundle_type_id' => $type->id, 'country_provider_id' => $pivot->id,
            'network_id' => 1, 'name' => '30GB', 'validity_days' => 30, 'price_usd' => 90, 'data_mb' => 30 * 1024,
            'currency' => 'USD', 'sim_bundle_id' => 66, 'active' => true]);
        $this->partialMock(VodacomBalanceService::class, fn ($mock) => $mock->shouldReceive('requestBalancesForMsisdn')
            ->zeroOrMoreTimes()->andReturn(['status' => 'queued', 'http_status' => 202]));
        return [$user, $sim, $bundle];
    }

    private function original(User $user, UserEsim $sim, Bundle $bundle, int $daysAgo = 10): Order
    {
        $order = $this->topup($user, $sim, $bundle);
        $order->update(['metadata' => ['user_esim_id' => $sim->id], 'recharge_status' => 'success',
            'recharge_completed_at' => now()->subDays($daysAgo), 'recharge_reference' => 'ORIGINAL']);
        $item = $order->orderItems()->first();
        $item->update(['bundle_name' => 'Original 24GB', 'data_amount' => 24 * 1024]);
        $sim->update(['order_id' => $order->id, 'bundle_id' => $bundle->id, 'order_item_id' => $item->id,
            'balances' => ['DATA' => 24 * 1024], 'balance_fetched_at' => now()->subHour()]);
        return $order;
    }

    private function topup(User $user, UserEsim $sim, Bundle $bundle): Order
    {
        $order = Order::create(['user_id' => $user->id, 'draft_id' => 'DRAFT-'.uniqid(), 'status' => 'paid',
            'payment_status' => 'paid', 'subtotal' => 90, 'discount_amount' => 0, 'total_amount' => 90,
            'currency' => 'USD', 'paid_at' => now(), 'metadata' => ['checkoutMode' => 'topup', 'user_esim_id' => $sim->id]]);
        OrderItem::create(['order_id' => $order->id, 'type' => 'bundle', 'bundle_id' => $bundle->id,
            'bundle_name' => '30GB', 'data_amount' => 30 * 1024, 'validity_days' => 30, 'price' => 90, 'currency' => 'USD']);
        return $order;
    }

    private function provider(array $body, bool $admin = true, int $status = 200): void
    {
        $this->mock(VodacomSimManagerService::class, function ($mock) use ($body, $admin, $status) {
            $expectation = $mock->shouldReceive('post')->once();
            $arguments = ['/api/recharge', [], \Mockery::on(fn ($payload) => $payload['product_id'] === 66 && $payload['msisdn'] === '255797053059')];
            if (! $admin) $arguments[] = \Mockery::any();
            $expectation->with(...$arguments)->andReturn(new \Illuminate\Http\Client\Response(Http::response($body, $status)->wait()));
        });
    }

    private function balanceCallback(int $mb): void
    {
        $this->postJson('/api/public/sims-balances/callback', [
            'msisdn' => '255797053059', 'balances' => ['DATA' => $mb, 'AIRTIME' => 0, 'SMS' => 0],
        ])->assertOk();
    }
}
