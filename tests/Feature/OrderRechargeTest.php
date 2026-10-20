<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundleType;
use App\Models\Country;
use App\Models\CountryProvider;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\OrderRechargeService;
use App\Services\Esim\VodacomBalanceService;
use App\Services\Esim\VodacomSimManagerService;
use App\Services\EvPay\EvPayCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderRechargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfill_order_posts_recharge_per_bundle_item(): void
    {
        [$order, $item] = $this->createPaidOrderFixture();

        $this->mockBalanceRefresh();
        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->with('/api/recharge', [], \Mockery::on(function (array $payload) {
                    return $payload['msisdn'] === '255797053059'
                        && $payload['network_id'] === 1
                        && $payload['product_id'] === 66
                        && str_starts_with($payload['reference'], 'RECHARGE')
                        && ! array_key_exists('airtime_amount', $payload);
                }), \Mockery::any())
                ->andReturn(Http::response([
                    'status' => 'SUCCESS',
                    'transaction_id' => '467dee1db6a3c1ef',
                ], 200));
        });

        $result = app(OrderRechargeService::class)->fulfillOrder($order);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $item->refresh();
        $order->refresh();
        $this->assertSame('success', $item->metadata['recharge']['status']);
        $this->assertSame(200, $item->metadata['recharge']['http_status']);
        $this->assertSame('success', $order->recharge_status);
        $this->assertSame('467dee1db6a3c1ef', $order->recharge_transaction_id);
        $this->assertNotNull($order->recharge_completed_at);
        $this->assertSame(200, $order->recharge_http_status);
    }

    public function test_fulfill_order_is_idempotent_for_already_sent_items(): void
    {
        [$order, $item] = $this->createPaidOrderFixture();

        $item->metadata = [
            'recharge' => [
                'reference' => 'RCH-20260519-1-1',
                'status' => 'sent',
                'requested_at' => now()->toIso8601String(),
            ],
        ];
        $item->save();

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        $result = app(OrderRechargeService::class)->fulfillOrder($order);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_evpay_paid_callback_triggers_recharge(): void
    {
        [$order] = $this->createPaidOrderFixture(pending: true);
        $order->payment_reference = 'ORD-20260519-001';
        $order->payment_status = 'pending';
        $order->status = 'pending_payment';
        $order->currency = 'USD';
        $order->total_amount = 50;
        $order->subtotal = 50;
        $order->metadata = array_merge($order->metadata ?? [], [
            'pricing_version' => 1,
            'checkoutMode' => 'topup',
            'simType' => 'esim',
            'msisdn' => '255797053059',
        ]);
        $order->save();

        $this->mockBalanceRefresh();
        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->with('/api/recharge', [], \Mockery::type('array'), \Mockery::any())
                ->andReturn(new \Illuminate\Http\Client\Response(
                    Http::response(['status' => 'SUCCESS', 'transaction_id' => 'tx-1'], 200)->wait()
                ));
        });

        $t = (string) time();
        $rawBody = json_encode([
            'event' => 'payment.succeeded',
            'timestamp' => (int) $t,
            'data' => [
                'id' => 'pay-123',
                'orderReference' => 'ORD-20260519-001',
                'status' => 'SUCCESS',
                'amount' => 50,
                'currency' => 'USD',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $t.'.'.$rawBody, (string) config('services.evpay.signing_key'));

        $request = Request::create('/api/payments/evpay/callback', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_SIGNATURE' => 't='.$t.',v1='.$signature,
            'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
            'HTTP_X_EVPAY_DELIVERY' => 'del-recharge-1',
        ], $rawBody);

        $result = app(EvPayCheckoutService::class)->handleWebhook($request);

        $this->assertSame('OK', $result['status']);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);

        $item = $order->orderItems()->first();
        $item->refresh();
        $this->assertContains($item->metadata['recharge']['status'] ?? null, ['sent', 'success', 'queued', 'pending']);
    }

    public function test_fulfill_order_without_esim_records_error_without_throwing(): void
    {
        [$order] = $this->createPaidOrderFixture(assignEsim: false);

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        $result = app(OrderRechargeService::class)->fulfillOrder($order);

        $this->assertSame(0, $result['processed']);
        $this->assertNotEmpty($result['errors']);

        $order->refresh();
        $this->assertSame('pending_esim', $order->recharge_status ?? $order->metadata['recharge_status'] ?? null);
        $this->assertNotEmpty($order->metadata['recharge_error'] ?? null);
    }

    public function test_recharge_resolves_esim_from_order_metadata_msisdn(): void
    {
        $user = User::factory()->create();
        $esim = Esim::create([
            'msisdn' => '255798092059',
            'network_id' => 1,
            'status' => 'AVAILABLE',
        ]);

        [$order] = $this->createPaidOrderFixture(assignEsim: false);
        $order->user_id = $user->id;
        $order->metadata = ['msisdn' => '255798092059'];
        $order->save();

        $this->mockBalanceRefresh();
        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->with('/api/recharge', [], \Mockery::type('array'), \Mockery::any())
                ->andReturn(Http::response(['status' => 'SUCCESS'], 200));
        });

        $result = app(OrderRechargeService::class)->rechargePaidOrder($order);

        $this->assertSame(1, $result['processed']);
        $this->assertDatabaseHas('user_esims', [
            'user_id' => $user->id,
            'esim_id' => $esim->id,
        ]);
    }

    public function test_duplicate_evpay_payment_id_skips_second_recharge(): void
    {
        [$order, $item] = $this->createPaidOrderFixture();
        $item->metadata = [
            'recharge' => [
                'reference' => 'RCH-EXISTING',
                'status' => 'sent',
                'requested_at' => now()->toIso8601String(),
            ],
        ];
        $item->save();
        $order->recharge_status = 'success';
        $order->recharge_reference = 'RCH-EXISTING';
        $order->recharge_transaction_id = 'tx-existing';
        $order->recharge_completed_at = now();
        $order->metadata = [
            'recharge_evpay_payment_id' => 'pay-123',
        ];
        $order->gateway_payment_id = 'pay-123';
        $order->save();

        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        $result = app(OrderRechargeService::class)->rechargePaidOrder($order, [
            'payment_id' => 'pay-123',
            'transaction_reference' => 'ORD-TEST',
        ]);

        $this->assertSame(0, $result['processed']);
        $this->assertSame('success', $result['recharge_status']);
    }

    public function test_fulfill_order_treats_nested_succeeded_as_success(): void
    {
        [$order, $item] = $this->createPaidOrderFixture();

        $this->mockBalanceRefresh();
        $this->mock(VodacomSimManagerService::class, function ($mock) {
            $mock->shouldReceive('post')
                ->once()
                ->andReturn(Http::response([
                    'data' => [
                        'id' => 2942535,
                        'status' => 'SUCCEEDED',
                        'reference' => 'ORDER-NESTED',
                        'price' => '500.00',
                        'msisdn' => '+255797053059',
                        'product_id' => 66,
                        'transaction_id' => '81835876014717703343',
                    ],
                ], 200));
        });

        $result = app(OrderRechargeService::class)->fulfillOrder($order);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $order->refresh();
        $item->refresh();
        $this->assertSame('success', $order->recharge_status);
        $this->assertSame('81835876014717703343', $order->recharge_transaction_id);
        $this->assertSame('success', $item->metadata['recharge']['status']);
    }

    /**
     * @return array{0: Order, 1: OrderItem}
     */
    private function createPaidOrderFixture(bool $pending = false, bool $assignEsim = true): array
    {
        $user = User::factory()->create();

        $bundleType = BundleType::firstOrCreate(['code' => 'DATA'], ['name' => 'Data only']);
        $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA']);
        $provider = Provider::create(['name' => 'Vodacom', 'slug' => 'vodacom']);
        $pivot = CountryProvider::create([
            'country_id' => $country->id,
            'provider_id' => $provider->id,
            'is_default' => true,
        ]);

        $bundle = Bundle::create([
            'bundle_type_id' => $bundleType->id,
            'country_provider_id' => $pivot->id,
            'name' => 'Nomad 10GB',
            'validity_days' => 30,
            'price_usd' => 90.00,
            'price_tzs' => 500,
            'currency' => 'USD',
            'sim_bundle_id' => 66,
            'active' => true,
        ]);

        if ($assignEsim) {
            $esim = Esim::create([
                'msisdn' => '255797053059',
                'network_id' => 1,
                'status' => 'MANAGED',
            ]);
            UserEsim::create([
                'user_id' => $user->id,
                'esim_id' => $esim->id,
            ]);
        }

        $order = Order::create([
            'draft_id' => 'DRAFT-TEST-'.uniqid(),
            'user_id' => $user->id,
            'status' => $pending ? 'pending_payment' : 'paid',
            'payment_status' => $pending ? 'pending' : 'paid',
            'subtotal' => 90.00,
            'discount_amount' => 0,
            'total_amount' => 90.00,
            'currency' => 'USD',
            'paid_at' => $pending ? null : now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'type' => 'bundle',
            'bundle_id' => $bundle->id,
            'bundle_name' => $bundle->name,
            'price' => 90.00,
            'currency' => 'USD',
        ]);

        $order->load('orderItems.bundle');

        return [$order, $item];
    }

    private function mockBalanceRefresh(): void
    {
        $this->mock(VodacomBalanceService::class, function ($mock) {
            $mock->shouldReceive('requestBalancesForMsisdn')
                ->zeroOrMoreTimes()
                ->andReturn(['status' => 'queued', 'http_status' => 202]);
        });
    }
}
