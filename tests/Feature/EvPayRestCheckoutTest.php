<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\User;
use App\Services\Esim\SimAssignmentService;
use App\Services\EvPay\EvPayCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvPayRestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $forceMysqlTesting = true;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evpay.base_url' => 'https://api-uat.evpay.co.tz',
            'services.evpay.client_id' => 'test-client-id',
            'services.evpay.client_secret' => 'test-client-secret',
            'services.evpay.signing_key' => 'test-signing-key',
            'services.evpay.callback_url' => 'https://api.test/api/payments/evpay/callback',
            'services.evpay.redirect_url' => 'https://thetravela.com/dashboard',
            'services.evpay.cancel_url' => 'https://thetravela.com/dashboard',
        ]);
    }

    public function test_checkout_creates_signed_card_payment_from_order_amount(): void
    {
        $this->fakeEvPayCardCreate();
        $order = $this->makePendingOrder(50);
        Sanctum::actingAs($order->user);

        $this->postJson("/api/orders/{$order->id}/evpay-checkout-url")
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://pay.evpay.example/hosted/abc')
            ->assertJsonPath('payment_url', 'https://pay.evpay.example/hosted/abc')
            ->assertJsonPath('payment_status', 'pending');

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame('evpay-ref-1', $order->gateway_payment_id);
        $this->assertStringStartsWith('ORD-', (string) $order->payment_reference);
        $this->assertNull($order->paid_at);

        Http::assertSent(function ($request) use ($order) {
            if (! str_ends_with($request->url(), '/api/v1/payment/card')) {
                return false;
            }

            $rawBody = $request->body();
            $payload = json_decode($rawBody, true);
            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-signing-key');

            return $payload['details']['amount'] == 50
                && $payload['details']['currency'] === 'USD'
                && $payload['orderReference'] === $order->fresh()->payment_reference
                && $payload['metadata']['orderId'] === $order->fresh()->payment_reference
                && $request->header('Idempotency-Key')[0] === $order->fresh()->payment_reference
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && strlen($timestamp) >= 13
                && (int) $timestamp > 1_000_000_000_000
                && hash_equals($expected, $signature);
        });
    }

    public function test_checkout_does_not_use_frontend_amount(): void
    {
        $this->fakeEvPayCardCreate();
        $order = $this->makePendingOrder(25);
        Sanctum::actingAs($order->user);

        $this->postJson("/api/orders/{$order->id}/evpay-checkout-url", [
            'amount' => 1,
            'total' => 1,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/v1/payment/card')) {
                return false;
            }

            $payload = json_decode($request->body(), true);

            return ($payload['details']['amount'] ?? null) == 25;
        });
    }

    public function test_missing_webhook_signature_returns_403(): void
    {
        $order = $this->makePendingOrder();

        $this->postJson('/api/payments/evpay/callback', [
            'event' => 'payment.succeeded',
            'timestamp' => time(),
            'data' => ['orderReference' => $order->payment_reference],
        ])->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_invalid_webhook_signature_returns_403(): void
    {
        $order = $this->makePendingOrder();
        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t);

        $this->call(
            'POST',
            '/api/payments/evpay/callback',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_EVPAY_SIGNATURE' => 't='.$t.',v1=deadbeef',
                'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
                'HTTP_X_EVPAY_DELIVERY' => 'del-invalid',
            ]),
            $body
        )->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_expired_webhook_timestamp_returns_403(): void
    {
        $order = $this->makePendingOrder();
        $t = (string) (time() - 400);
        $body = $this->webhookBody($order, (int) $t);

        $this->postSignedWebhook($body, $t, 'del-expired')
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_valid_success_webhook_marks_paid_and_fulfills_once(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder(50);
        $order->gateway_payment_id = 'evpay-ref-1';
        $order->save();

        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t, 50, 'SUCCESS', 'payment.succeeded', 'evpay-ref-1');

        $this->postSignedWebhook($body, $t, 'del-success')
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_success_webhook_matches_order_via_metadata_when_order_reference_missing(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $body = json_encode([
            'event' => 'payment.succeeded',
            'timestamp' => (int) $t,
            'data' => [
                'id' => 'evpay-ref-1',
                'status' => 'SUCCESS',
                'amount' => 50,
                'currency' => 'USD',
                'metadata' => [
                    'orderId' => $order->payment_reference,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->postSignedWebhook($body, $t, 'del-meta')
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_success_webhook_matches_order_via_gateway_payment_id(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder(50);
        $order->gateway_payment_id = 'E020726AC15K4T29B';
        $order->save();

        $t = (string) time();
        $body = json_encode([
            'event' => 'payment.settled',
            'timestamp' => (int) $t,
            'data' => [
                'id' => 'E020726AC15K4T29B',
                'status' => 'SETTLED',
                'amount' => 50,
                'currency' => 'USD',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->postSignedWebhook($body, $t, 'del-gateway-id')
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_webhook_without_order_identifiers_returns_422(): void
    {
        $this->makePendingOrder(50);
        $t = (string) time();
        $body = json_encode([
            'event' => 'payment.succeeded',
            'timestamp' => (int) $t,
            'data' => [
                'status' => 'SUCCESS',
                'amount' => 50,
                'currency' => 'USD',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->postSignedWebhook($body, $t, 'del-no-ref')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Missing payment reference in webhook.');
    }

    public function test_duplicate_webhook_does_not_fulfill_twice(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t, 50);

        $this->postSignedWebhook($body, $t, 'del-dup')->assertOk();
        $this->postSignedWebhook($body, $t, 'del-dup')->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_mismatched_amount_is_rejected(): void
    {
        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t, 100);

        $this->postSignedWebhook($body, $t, 'del-amount')
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_mismatched_currency_is_rejected(): void
    {
        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $payload = json_decode($this->webhookBody($order, (int) $t, 50), true);
        $payload['data']['currency'] = 'TZS';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->postSignedWebhook($body, $t, 'del-currency')
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_failed_webhook_marks_unpaid_order_failed(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->never();
        });

        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t, 50, 'FAILED', 'payment.failed');

        $this->postSignedWebhook($body, $t, 'del-fail')
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('payment_failed', $order->status);
        $this->assertNull($order->paid_at);
    }

    public function test_browser_redirect_alone_never_marks_order_paid(): void
    {
        $this->fakeEvPayCardCreate();
        $order = $this->makePendingOrder();
        Sanctum::actingAs($order->user);

        $this->postJson("/api/orders/{$order->id}/evpay-checkout-url")->assertOk();
        $this->get('/api/payments/evpay/callback')->assertMethodNotAllowed();

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_store_order_ignores_client_paid_status(): void
    {
        $this->fakeEvPayCardCreate();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/orders', $this->storeOrderPayload($user, [
            'payment' => [
                'status' => 'paid',
                'paid_at' => now()->toIso8601String(),
                'reference' => 'CLIENT-FAKE',
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://pay.evpay.example/hosted/abc')
            ->assertJsonPath('data.payment_url', 'https://pay.evpay.example/hosted/abc');

        $order = Order::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNotSame('CLIENT-FAKE', $order->payment_reference);
    }

    public function test_customer_cannot_create_order_for_another_user(): void
    {
        $this->fakeEvPayCardCreate();
        $actor = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/orders', $this->storeOrderPayload($other))
            ->assertCreated();

        $this->assertTrue(Order::query()->where('user_id', $actor->id)->exists());
        $this->assertFalse(Order::query()->where('user_id', $other->id)->exists());
    }

    public function test_admin_can_create_order_for_another_user(): void
    {
        $this->fakeEvPayCardCreate();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/orders', $this->storeOrderPayload($customer))
            ->assertCreated();

        $this->assertTrue(Order::query()->where('user_id', $customer->id)->exists());
        $this->assertFalse(Order::query()->where('user_id', $admin->id)->exists());
    }

    public function test_fulfillment_exception_still_returns_ok_and_keeps_order_paid(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')
                ->once()
                ->andThrow(new \RuntimeException('inventory unavailable'));
        });

        $order = $this->makePendingOrder(50);
        $t = (string) time();
        $body = $this->webhookBody($order, (int) $t, 50);

        $this->postSignedWebhook($body, $t, 'del-fulfill-fail')
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_missed_webhook_is_reconciled_from_evpay_status_on_orders_list(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder(50);
        $order->gateway_payment_id = '01M20M1S3G0YWJ10P185HSBNNM';
        $order->save();

        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
            'https://api-uat.evpay.co.tz/api/v1/payment/*' => Http::response([
                'status' => 'SUCCESS',
                'data' => [
                    'id' => '01M20M1S3G0YWJ10P185HSBNNM',
                    'orderReference' => $order->payment_reference,
                    'status' => 'SUCCESS',
                    'amount' => 50,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        Sanctum::actingAs($order->user);

        $this->getJson('/api/me/orders')
            ->assertOk()
            ->assertJsonPath('data.0.payment_status', 'paid')
            ->assertJsonPath('data.0.status', 'paid');

        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_reconcile_leaves_order_pending_when_evpay_still_pending(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->never();
        });

        $order = $this->makePendingOrder(50);
        $order->gateway_payment_id = 'evpay-pending-1';
        $order->save();

        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
            'https://api-uat.evpay.co.tz/api/v1/payment/*' => Http::response([
                'status' => 'PENDING',
                'data' => [
                    'id' => 'evpay-pending-1',
                    'status' => 'PENDING',
                    'amount' => 50,
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        app(EvPayCheckoutService::class)->reconcilePendingOrder($order);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    private function fakeEvPayCardCreate(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
            'https://api-uat.evpay.co.tz/api/v1/payment/card' => Http::response([
                'status' => 'PENDING',
                'data' => [
                    'reference' => 'evpay-ref-1',
                    'paymentUrl' => 'https://pay.evpay.example/hosted/abc',
                ],
            ], 201),
        ]);
    }

    private function makePendingOrder(float $amount = 50): Order
    {
        $user = User::factory()->create();

        $order = Order::create([
            'draft_id' => 'DRAFT-REST-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'currency' => 'USD',
            'metadata' => ['pricing_version' => 1],
        ]);

        return app(EvPayCheckoutService::class)->prepare($order);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storeOrderPayload(User $customer, array $overrides = []): array
    {
        $countryId = DB::table('countries')->insertGetId([
            'name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'USD',
        ]);
        $providerId = DB::table('providers')->insertGetId(['name' => 'Test', 'slug' => 'test']);
        $countryProviderId = DB::table('country_provider')->insertGetId([
            'country_id' => $countryId, 'provider_id' => $providerId,
        ]);
        $typeId = \App\Models\BundleType::firstOrCreate(['code' => 'DATA'], ['name' => 'Data'])->id;
        $bundle = Bundle::create([
            'bundle_type_id' => $typeId, 'country_provider_id' => $countryProviderId,
            'name' => 'Test plan', 'data_mb' => 1024, 'validity_days' => 30,
            'price_usd' => 50, 'currency' => 'USD', 'active' => true,
        ]);
        $payload = [
            'draft_id' => 'DRAFT-STORE-'.uniqid(),
            'user_id' => $customer->id,
            'simType' => 'esim',
            'trip' => [
                'destination_country' => 'TZ',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(3)->toDateString(),
                'duration_days' => 3,
            ],
            'items' => [
                [
                    'type' => 'bundle',
                    'bundle_id' => $bundle->id,
                    'bundle_name' => 'Test plan',
                    'data_amount' => 1024,
                    'validity_days' => 7,
                    'price' => 50,
                    'currency' => 'USD',
                ],
            ],
            'pricing' => [
                'subtotal' => 50,
                'discount_amount' => 0,
                'total_amount' => 50,
                'currency' => 'USD',
            ],
            'kyc' => [
                'passport_id' => 'AB123456',
                'passport_country' => 'TZ',
                'nationality' => 'Tanzanian',
                'gender' => 'Male',
                'reason_for_travel' => 'Tourism',
            ],
            'payment' => [
                'status' => 'pending',
            ],
            'order_metadata' => [
                'source' => 'web',
                'platform' => 'web',
                'created_at' => now()->toIso8601String(),
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    private function webhookBody(
        Order $order,
        int $timestamp,
        float|int $amount = 50,
        string $status = 'SUCCESS',
        string $event = 'payment.succeeded',
        string $id = 'evpay-ref-1',
    ): string {
        return json_encode([
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => [
                'id' => $id,
                'orderReference' => $order->payment_reference ?: 'ORD-PENDING',
                'status' => $status,
                'amount' => $amount,
                'currency' => 'USD',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function postSignedWebhook(string $rawBody, string $t, string $delivery)
    {
        $signature = hash_hmac('sha256', $t.'.'.$rawBody, 'test-signing-key');

        return $this->call(
            'POST',
            '/api/payments/evpay/callback',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_EVPAY_SIGNATURE' => 't='.$t.',v1='.$signature,
                'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
                'HTTP_X_EVPAY_DELIVERY' => $delivery,
            ]),
            $rawBody
        );
    }
}
