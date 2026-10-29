<?php

namespace App\Services\EvPay;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EvPayClientService
{
    private const ACCESS_TOKEN_CACHE_KEY = 'evpay_access_token';

    public function getAccessToken(): string
    {
        if ($token = Cache::get(self::ACCESS_TOKEN_CACHE_KEY)) {
            return $token;
        }

        $baseUrl = $this->baseUrl();
        $clientId = (string) config('services.evpay.client_id');
        $clientSecret = (string) config('services.evpay.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('EvPay API configuration is incomplete.');
        }

        $response = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post('/api/auth/v1', [
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'EvPay authentication failed: '.$response->json('message', 'Unknown error')
            );
        }

        $token = $response->json('data.accessToken');
        $expiresIn = (int) $response->json('data.expiresIn', 36000);

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('EvPay did not return an access token.');
        }

        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, max(1, $expiresIn - 30));

        return $token;
    }

    public function createCardPayment(
        array $payload,
        ?string $idempotencyKey = null
    ): array {
        /*
         * We sign this exact JSON text and send the same text to EvPay.
         * Do not sign one JSON string and then let Laravel recreate it.
         */
        $rawBody = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response = $this->authenticatedRequest('POST', '/api/v1/payment/card', $rawBody, $idempotencyKey);

        if (! $response->successful()) {
            throw new RuntimeException(
                'EvPay card payment failed with HTTP '
                .$response->status().': '
                .$response->json('message', 'Unknown EvPay error')
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Poll EvPay when a webhook delivery was missed.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        $id = trim($paymentId);
        if ($id === '') {
            throw new RuntimeException('EvPay payment id is missing.');
        }

        $response = $this->authenticatedRequest('GET', '/api/v1/payment/'.rawurlencode($id));

        if (! $response->successful()) {
            throw new RuntimeException(
                'EvPay payment status failed with HTTP '
                .$response->status().': '
                .$response->json('message', 'Unknown EvPay error')
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function authenticatedRequest(
        string $method,
        string $path,
        string $rawBody = '',
        ?string $idempotencyKey = null,
    ): Response {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->getAccessToken();
            $timestamp = (string) floor(microtime(true) * 1000);
            $headers = [
                'X-Timestamp' => $timestamp,
                'X-Signature' => $this->generateSignature($timestamp, $rawBody),
            ];
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }

            $request = Http::baseUrl($this->baseUrl())
                ->withToken($token)
                ->acceptJson()
                ->timeout($method === 'POST' ? 30 : 15)
                ->withHeaders($headers);

            $response = $method === 'POST'
                ? $request->withBody($rawBody, 'application/json')->post($path)
                : $request->get($path);

            if ($response->status() !== 401) {
                return $response;
            }

            // A cached token can be revoked before its advertised expiry. Refresh
            // once, retaining the exact body and idempotency key on payment retry.
            Cache::forget(self::ACCESS_TOKEN_CACHE_KEY);
        }

        return $response;
    }

    /**
     * Verify an inbound EvPay webhook using the raw body and X-EvPay-Signature.
     *
     * @return array<string, mixed>
     */
    public function verifiedWebhookPayload(Request $request): array
    {
        $rawBody = (string) $request->getContent();
        $header = $request->header('X-EvPay-Signature');

        if (! is_string($header) || $header === '') {
            throw new EvPayWebhookException('Missing EvPay webhook signature.');
        }

        $parsed = $this->parseWebhookSignatureHeader($header);
        if ($parsed === null) {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        ['t' => $t, 'v1' => $v1] = $parsed;

        if (! ctype_digit($t)) {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        $age = abs(time() - (int) $t);
        if ($age > 300) {
            throw new EvPayWebhookException('Expired EvPay webhook timestamp.');
        }

        if ($rawBody === '') {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        $bodyTimestamp = $payload['timestamp'] ?? null;
        if ((string) $bodyTimestamp !== $t) {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        $expected = $this->generateSignature($t, $rawBody);
        if (! hash_equals($expected, strtolower($v1))) {
            throw new EvPayWebhookException('Invalid EvPay webhook signature.');
        }

        return $payload;
    }

    /**
     * @return array{t: string, v1: string}|null
     */
    private function parseWebhookSignatureHeader(string $header): ?array
    {
        $t = null;
        $v1 = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $t = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $v1 = substr($part, 3);
            }
        }

        if (! is_string($t) || $t === '' || ! is_string($v1) || $v1 === '') {
            return null;
        }

        return ['t' => $t, 'v1' => strtolower($v1)];
    }

    private function generateSignature(
        string $timestamp,
        string $rawBody
    ): string {
        $signingKey = trim((string) config('services.evpay.signing_key'));

        if ($signingKey === '') {
            throw new RuntimeException('EvPay signing key is not configured.');
        }

        return hash_hmac(
            'sha256',
            $timestamp.'.'.$rawBody,
            $signingKey
        );
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.evpay.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('EvPay API configuration is incomplete.');
        }

        return $baseUrl;
    }
}
