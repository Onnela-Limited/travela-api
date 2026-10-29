<?php

namespace Tests\Unit;

use App\Services\EvPay\EvPayClientService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EvPayClientServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evpay.base_url' => 'https://api-uat.evpay.co.tz',
            'services.evpay.client_id' => 'test-client-id',
            'services.evpay.client_secret' => 'test-client-secret',
            'services.evpay.signing_key' => 'test-signing-key',
        ]);

        Cache::flush();
    }

    public function test_get_access_token_authenticates_and_caches(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
        ]);

        $service = app(EvPayClientService::class);

        $this->assertSame('tok-abc', $service->getAccessToken());
        $this->assertSame('tok-abc', $service->getAccessToken());

        Http::assertSentCount(1);
    }

    public function test_get_access_token_fails_when_auth_is_rejected(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'message' => 'Invalid client',
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EvPay authentication failed: Invalid client');

        app(EvPayClientService::class)->getAccessToken();
    }

    public function test_create_card_payment_signs_and_sends_the_same_json_body(): void
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
                'data' => ['paymentId' => 'pay-1'],
            ], 200),
        ]);

        $payload = [
            'amount' => '1000.00',
            'currency' => 'TZS',
            'reference' => 'ORD-1',
            'callbackUrl' => 'https://api.test/api/evpay/webhook',
        ];
        $rawBody = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $result = app(EvPayClientService::class)->createCardPayment($payload, 'idem-123');

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('pay-1', $result['data']['paymentId']);

        Http::assertSent(function ($request) use ($rawBody) {
            if (! str_ends_with($request->url(), '/api/v1/payment/card')) {
                return false;
            }

            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-signing-key');

            return $request->body() === $rawBody
                && $request->header('Idempotency-Key')[0] === 'idem-123'
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && strlen($request->header('X-Timestamp')[0] ?? '') >= 13
                && (int) ($request->header('X-Timestamp')[0] ?? 0) > 1_000_000_000_000
                && hash_equals($expected, $signature);
        });
    }

    public function test_create_card_payment_fails_without_signing_key(): void
    {
        config(['services.evpay.signing_key' => '']);

        Cache::put('evpay_access_token', 'tok-abc', 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EvPay signing key is not configured.');

        app(EvPayClientService::class)->createCardPayment(['amount' => '1']);
    }

    public function test_card_payment_refreshes_rejected_token_and_preserves_payment_request(): void
    {
        Cache::put('evpay_access_token', 'expired-token', 60);
        Http::fake([
            '*/api/auth/v1' => Http::response(['data' => ['accessToken' => 'fresh-token', 'expiresIn' => 3600]]),
            '*/api/v1/payment/card' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['data' => ['paymentUrl' => 'https://pay.test/checkout']], 200),
        ]);

        $payload = ['amount' => '20.00', 'currency' => 'USD', 'reference' => 'ORD-227'];
        $result = app(EvPayClientService::class)->createCardPayment($payload, 'ORD-227');

        $this->assertSame('https://pay.test/checkout', $result['data']['paymentUrl']);
        Http::assertSentCount(3);
        $payments = Http::recorded(fn ($request) => str_ends_with($request->url(), '/payment/card'))->values();
        $this->assertCount(2, $payments);
        $this->assertTrue($payments[0][0]->hasHeader('Authorization', 'Bearer expired-token'));
        $this->assertTrue($payments[1][0]->hasHeader('Authorization', 'Bearer fresh-token'));
        foreach ($payments as [$request]) {
            $this->assertSame(json_encode($payload), $request->body());
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'ORD-227'));
            $this->assertSame(
                hash_hmac('sha256', $request->header('X-Timestamp')[0].'.'.$request->body(), 'test-signing-key'),
                $request->header('X-Signature')[0],
            );
        }
        $this->assertSame('fresh-token', Cache::get('evpay_access_token'));
    }

    public function test_card_payment_stops_after_one_refresh_when_unauthorized_persists(): void
    {
        Cache::put('evpay_access_token', 'expired-token', 60);
        Http::fake([
            '*/api/auth/v1' => Http::response(['data' => ['accessToken' => 'fresh-token']]),
            '*/api/v1/payment/card' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            app(EvPayClientService::class)->createCardPayment(['amount' => '20'], 'ORD-227');
            $this->fail('A second 401 must remain an error.');
        } catch (RuntimeException $e) {
            $this->assertSame('EvPay card payment failed with HTTP 401: Unauthorized', $e->getMessage());
        }
        Http::assertSentCount(3);
        $this->assertNull(Cache::get('evpay_access_token'));
    }

    public function test_card_payment_does_not_retry_other_errors(): void
    {
        Cache::put('evpay_access_token', 'valid-token', 60);
        Http::fake(['*/api/v1/payment/card' => Http::response(['message' => 'Invalid payment'], 422)]);

        try {
            app(EvPayClientService::class)->createCardPayment(['amount' => '20']);
            $this->fail('A validation error must remain an error.');
        } catch (RuntimeException $e) {
            $this->assertSame('EvPay card payment failed with HTTP 422: Invalid payment', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_get_payment_refreshes_rejected_token(): void
    {
        Cache::put('evpay_access_token', 'expired-token', 60);
        Http::fake([
            '*/api/auth/v1' => Http::response(['data' => ['accessToken' => 'fresh-token']]),
            '*/api/v1/payment/pay-1' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['status' => 'SUCCESS'], 200),
        ]);

        $this->assertSame('SUCCESS', app(EvPayClientService::class)->getPayment('pay-1')['status']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_get_payment_signs_get_request(): void
    {
        Cache::put('evpay_access_token', 'tok-abc', 60);

        Http::fake([
            'https://api-uat.evpay.co.tz/api/v1/payment/pay-1' => Http::response([
                'status' => 'SUCCESS',
                'data' => [
                    'id' => 'pay-1',
                    'status' => 'SUCCESS',
                ],
            ], 200),
        ]);

        $result = app(EvPayClientService::class)->getPayment('pay-1');

        $this->assertSame('SUCCESS', $result['status']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/v1/payment/pay-1')) {
                return false;
            }

            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.', 'test-signing-key');

            return $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && strlen($timestamp) >= 13
                && hash_equals($expected, $signature);
        });
    }
}
