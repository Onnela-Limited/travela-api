<?php

namespace App\Services\EvPay;

use App\Models\Esim;
use App\Models\Order;
use App\Services\Esim\SimAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EvPayCheckoutService
{
    private const CHARGE_CURRENCY = 'USD';

    private const MIN_USD_AMOUNT = 1.0;

    private const SUCCESS_EVENTS = ['payment.succeeded', 'payment.settled'];

    private const SUCCESS_STATUSES = ['SUCCESS', 'SETTLED'];

    public function __construct(
        private readonly EvPayClientService $client,
        private readonly SimAssignmentService $simAssignment,
    ) {}

    public function prepare(Order $order): Order
    {
        if ($order->payment_status === 'paid') {
            return $order;
        }

        if ($this->shouldRegenerateReference($order->payment_reference)) {
            $order->payment_reference = $this->generateUniquePaymentReference();
        }

        $order->payment_gateway = 'evpay';
        $order->payment_status = 'pending';

        if ($order->status !== 'paid') {
            $order->status = 'pending_payment';
        }

        $order->save();

        return $order;
    }

    /**
     * @return array{
     *     order_id: int,
     *     payment_reference: string,
     *     checkout_url: string,
     *     payment_url: string,
     *     payment_status: string
     * }
     */
    public function startCardPayment(Order $order): array
    {
        $order->loadMissing('user');

        if ($order->payment_status === 'paid') {
            throw new RuntimeException('This order is already paid.');
        }

        if (data_get($order->metadata, 'pricing_version') !== 1) {
            throw new RuntimeException('This order requires pricing review. Please create a new checkout.');
        }

        $order = $this->prepare($order);

        $existingUrl = $this->storedPaymentUrl($order);
        if ($order->payment_status === 'pending' && is_string($existingUrl) && $existingUrl !== '') {
            return $this->checkoutResponse($order, $existingUrl);
        }

        $payload = $this->cardPaymentPayload($order);
        $response = $this->client->createCardPayment($payload, (string) $order->payment_reference);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $paymentUrl = trim((string) ($data['paymentUrl'] ?? ''));
        $evpayReference = trim((string) ($data['reference'] ?? ''));

        if ($paymentUrl === '') {
            throw new RuntimeException('EvPay did not return a payment URL.');
        }

        $order->payment_gateway = 'evpay';
        $order->payment_status = 'pending';
        $order->status = 'pending_payment';
        if ($evpayReference !== '') {
            $order->gateway_payment_id = $evpayReference;
        }
        $order->payment_payload = [
            'request' => $payload,
            'response' => [
                'reference' => $evpayReference !== '' ? $evpayReference : null,
                'paymentUrl' => $paymentUrl,
                'status' => $response['status'] ?? 'PENDING',
            ],
        ];
        $order->save();

        return $this->checkoutResponse($order, $paymentUrl);
    }

    /**
     * @return array{status: string}
     */
    public function handleWebhook(Request $request): array
    {
        $payload = $this->client->verifiedWebhookPayload($request);

        $event = (string) ($payload['event'] ?? $request->header('X-EvPay-Event') ?? '');
        $deliveryId = trim((string) ($request->header('X-EvPay-Delivery') ?? ''));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $order = $this->findOrderForWebhook($data);

        Log::info('EvPay webhook received', [
            'event' => $event,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'order_id' => $order->id,
            'order_reference' => $order->payment_reference,
        ]);

        $callbackRecord = $this->safeCallbackRecord($request, $payload, $event, $deliveryId);

        return $this->processNotification($order, $data, $event, $deliveryId, $callbackRecord);
    }

    /**
     * EvPay docs: if a webhook delivery is missed, poll GET /api/v1/payment/{id}.
     * Dashboard polling uses this so a completed card payment still marks the order paid.
     */
    public function reconcilePendingOrder(Order $order): Order
    {
        if ($order->payment_status === 'paid' || $order->status === 'paid') {
            return $order;
        }

        if ($order->payment_gateway !== 'evpay') {
            return $order;
        }

        $paymentId = trim((string) ($order->gateway_payment_id ?? ''));
        if ($paymentId === '') {
            return $order;
        }

        $lockKey = 'evpay_reconcile_'.$order->id;
        if (! Cache::add($lockKey, 1, 10)) {
            return $order;
        }

        try {
            $response = $this->client->getPayment($paymentId);
        } catch (\Throwable $e) {
            Log::warning('EvPay payment status poll failed', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return $order;
        }

        $data = $this->paymentDataFromStatusResponse($response);
        $status = strtoupper((string) ($data['status'] ?? ''));
        $event = $this->eventFromStatus($status);
        $deliveryId = 'poll:'.$paymentId.':'.$status;

        try {
            $this->processNotification($order, $data, $event, $deliveryId, [
                'received_at' => now()->toIso8601String(),
                'event' => $event,
                'delivery_id' => $deliveryId,
                'source' => 'status_poll',
                'payload' => $response,
                'headers' => [],
            ], false);
        } catch (EvPayWebhookException $e) {
            Log::warning('EvPay status poll did not apply to order', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return $order;
        }

        return $order->fresh() ?? $order;
    }

    public function reconcilePendingOrdersForUser(int $userId): void
    {
        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('payment_gateway', 'evpay')
            ->whereNotNull('gateway_payment_id')
            ->where(function ($q) {
                $q->whereIn('payment_status', ['pending', 'pending_payment'])
                    ->orWhere('status', 'pending_payment');
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($orders as $order) {
            $this->reconcilePendingOrder($order);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $callbackRecord
     * @return array{status: string}
     */
    private function processNotification(
        Order $order,
        array $data,
        string $event,
        string $deliveryId,
        array $callbackRecord,
        bool $strictMatch = true,
    ): array {
        if ($deliveryId !== '' && $this->deliveryAlreadyProcessed($order, $deliveryId)) {
            Log::info('EvPay webhook duplicate delivery', [
                'order_id' => $order->id,
                'delivery_id' => $deliveryId,
            ]);

            return ['status' => 'OK'];
        }

        $shouldFulfill = false;
        $fulfillContext = [];

        DB::transaction(function () use (
            $order,
            $data,
            $event,
            $callbackRecord,
            $deliveryId,
            $strictMatch,
            &$shouldFulfill,
            &$fulfillContext,
        ) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($this->deliveryAlreadyProcessed($order, $deliveryId)) {
                return;
            }

            $this->appendCallback($order, $callbackRecord, $deliveryId);

            if ($order->payment_status === 'paid') {
                $order->save();

                Log::info('EvPay webhook duplicate (already paid)', [
                    'order_id' => $order->id,
                    'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
                ]);

                return;
            }

            if ($this->isHoldOrReversalEvent($event)) {
                $order->save();
                Log::warning('EvPay webhook requires manual reconciliation', [
                    'order_id' => $order->id,
                    'event' => $event,
                    'status' => $data['status'] ?? null,
                ]);

                return;
            }

            if ($this->isFailedEvent($event, $data)) {
                $order->payment_status = 'failed';
                $order->status = 'payment_failed';
                $this->clearStoredPaymentUrl($order);
                $order->save();

                return;
            }

            if (! $this->isSuccessfulEvent($event, $data)) {
                $order->save();

                return;
            }

            $this->assertWebhookMatchesOrder($order, $data, $strictMatch);

            // Older orders contain browser-supplied prices. A valid gateway payment
            // for those amounts is not proof that the catalog bundle was paid for.
            if (data_get($order->metadata, 'pricing_version') !== 1) {
                throw new EvPayWebhookException('This order requires manual pricing review before fulfillment.', 422);
            }

            $transactionId = trim((string) ($data['id'] ?? ''));
            if ($transactionId !== '' && ! $order->gateway_payment_id) {
                $order->gateway_payment_id = $transactionId;
            }

            $order->payment_gateway = 'evpay';
            $order->payment_status = 'paid';
            $order->status = 'paid';
            if ($order->paid_at === null) {
                $order->paid_at = now();
            }
            $order->save();

            $shouldFulfill = true;
            $fulfillContext = [
                'payment_id' => $transactionId !== '' ? $transactionId : $order->gateway_payment_id,
                'transaction_reference' => $order->payment_reference,
            ];
        });

        if ($shouldFulfill) {
            try {
                $paidOrder = Order::query()->find($order->id);
                if ($paidOrder && $paidOrder->payment_status === 'paid') {
                    $this->simAssignment->fulfillPaidOrder($paidOrder, $fulfillContext);
                }
            } catch (\Throwable $e) {
                Log::error('Order fulfillment failed after EvPay webhook', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['status' => 'OK'];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function paymentDataFromStatusResponse(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        if ($data === []) {
            $data = $response;
        }

        if (! isset($data['status']) && isset($response['status'])) {
            $data['status'] = $response['status'];
        }

        if (! isset($data['id']) && isset($response['id'])) {
            $data['id'] = $response['id'];
        }

        if (! isset($data['id']) && isset($response['data']['reference'])) {
            $data['id'] = $response['data']['reference'];
        }

        return is_array($data) ? $data : [];
    }

    private function eventFromStatus(string $status): string
    {
        return match ($status) {
            'SUCCESS' => 'payment.succeeded',
            'SETTLED' => 'payment.settled',
            'FAILED' => 'payment.failed',
            'ON-HOLD' => 'payment.on_hold',
            'REFUNDED' => 'payment.refunded',
            'REVERSED' => 'payment.reversed',
            default => '',
        };
    }

    /**
     * Match the webhook to a Travela order using EvPay's echoed merchant reference,
     * then EvPay's transaction id if the reference field was omitted.
     *
     * @param  array<string, mixed>  $data
     */
    private function findOrderForWebhook(array $data): Order
    {
        $references = $this->webhookMerchantReferences($data);

        foreach ($references as $reference) {
            $order = Order::where('payment_reference', $reference)->first();
            if ($order) {
                return $order;
            }
        }

        $gatewayIds = [];
        foreach ([$data['id'] ?? null, $data['reference'] ?? null] as $value) {
            $id = trim((string) $value);
            if ($id !== '' && ! in_array($id, $gatewayIds, true)) {
                $gatewayIds[] = $id;
            }
        }

        if ($gatewayIds !== []) {
            $order = Order::whereIn('gateway_payment_id', $gatewayIds)->first();
            if ($order) {
                return $order;
            }
        }

        if ($references === [] && $gatewayIds === []) {
            throw new EvPayWebhookException('Missing payment reference in webhook.', 422);
        }

        $hint = $references[0] ?? $gatewayIds[0];

        throw new EvPayWebhookException('Order not found for reference: '.$hint, 404);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function webhookMerchantReferences(array $data): array
    {
        $candidates = [
            $data['orderReference'] ?? null,
        ];

        $metadata = $data['metadata'] ?? null;
        if (is_array($metadata)) {
            $candidates[] = $metadata['orderId'] ?? null;
            $candidates[] = $metadata['orderReference'] ?? null;
        }

        $references = [];
        foreach ($candidates as $value) {
            $reference = trim((string) $value);
            if ($reference !== '' && ! in_array($reference, $references, true)) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPaymentPayload(Order $order): array
    {
        $this->assertCheckoutConfig();

        $currency = strtoupper((string) ($order->currency ?: self::CHARGE_CURRENCY));
        if ($currency !== self::CHARGE_CURRENCY) {
            throw new RuntimeException('EvPay card payments require USD currency.');
        }

        $amount = $this->chargeAmount($order);
        if ($amount < self::MIN_USD_AMOUNT) {
            throw new RuntimeException('EvPay card payments require a minimum of USD 1.00.');
        }

        $user = $order->user;
        [$firstName, $lastName] = $this->customerNames($user?->name);

        return [
            'paymentType' => 'card',
            'details' => [
                'amount' => $amount,
                'currency' => self::CHARGE_CURRENCY,
                'redirectUrl' => $this->customerReturnUrl($order),
                'cancelUrl' => rtrim((string) config('services.evpay.cancel_url'), '/'),
            ],
            'customer' => [
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $user?->email ?? 'customer@example.com',
                'address' => $user?->address ?? 'Dar es Salaam',
                'city' => 'Dar es Salaam',
                'state' => 'DSM',
                'postcode' => $user?->postal_code ?? '00000',
                'country' => 'TZ',
            ],
            'phoneNumber' => preg_replace('/\D+/', '', $user?->phone ?? '255700000000') ?: '255700000000',
            'orderReference' => (string) $order->payment_reference,
            'callbackUrl' => (string) config('services.evpay.callback_url'),
            'metadata' => [
                'orderId' => (string) $order->payment_reference,
            ],
        ];
    }

    private function assertCheckoutConfig(): void
    {
        $required = [
            'base_url' => config('services.evpay.base_url'),
            'client_id' => config('services.evpay.client_id'),
            'client_secret' => config('services.evpay.client_secret'),
            'signing_key' => config('services.evpay.signing_key'),
            'callback_url' => config('services.evpay.callback_url'),
            'redirect_url' => config('services.evpay.redirect_url'),
            'cancel_url' => config('services.evpay.cancel_url'),
        ];

        foreach ($required as $name => $value) {
            if (trim((string) $value) === '') {
                throw new RuntimeException('EvPay configuration is incomplete: '.$name.' is missing.');
            }
        }
    }

    /**
     * After card payment EvPay sends the customer here. Query flags start dashboard balance polling
     * even when the return tab has empty sessionStorage.
     */
    private function customerReturnUrl(Order $order): string
    {
        $base = rtrim((string) config('services.evpay.redirect_url'), '/');
        $query = ['await_balance' => '1'];

        $order->loadMissing('orderItems');
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $msisdn = $meta['msisdn'] ?? null;
        if (is_string($msisdn) && trim($msisdn) !== '') {
            $query['msisdn'] = Esim::normalizeMsisdn($msisdn);
        }

        $purchasedMb = 0;
        foreach ($order->orderItems as $item) {
            if (is_numeric($item->data_amount)) {
                $purchasedMb += (int) $item->data_amount;
            }
        }
        if ($purchasedMb > 0) {
            $query['purchased_mb'] = (string) $purchasedMb;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertWebhookMatchesOrder(Order $order, array $data, bool $strict = true): void
    {
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency !== '') {
            if ($currency !== self::CHARGE_CURRENCY) {
                throw new EvPayWebhookException('Webhook currency does not match the order.', 422);
            }
        } elseif ($strict) {
            throw new EvPayWebhookException('Webhook currency does not match the order.', 422);
        }

        $hasAmount = array_key_exists('amount', $data)
            && $data['amount'] !== null
            && $data['amount'] !== '';

        if ($hasAmount) {
            if ($this->amountToCents($data['amount']) !== $this->amountToCents($order->total_amount)) {
                throw new EvPayWebhookException('Webhook amount does not match the order.', 422);
            }
        } elseif ($strict) {
            throw new EvPayWebhookException('Webhook amount does not match the order.', 422);
        }

        $stored = trim((string) ($order->gateway_payment_id ?? ''));
        $webhookId = trim((string) ($data['id'] ?? ''));
        $webhookReference = trim((string) ($data['reference'] ?? ''));

        if ($stored !== '' && $webhookId !== '' && $stored !== $webhookId && $stored !== $webhookReference) {
            throw new EvPayWebhookException('Webhook payment id does not match the order.', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isSuccessfulEvent(string $event, array $data): bool
    {
        $status = strtoupper((string) ($data['status'] ?? ''));

        return in_array($event, self::SUCCESS_EVENTS, true)
            || in_array($status, self::SUCCESS_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isFailedEvent(string $event, array $data): bool
    {
        $status = strtoupper((string) ($data['status'] ?? ''));

        return $event === 'payment.failed' || $status === 'FAILED';
    }

    private function isHoldOrReversalEvent(string $event): bool
    {
        return in_array($event, ['payment.on_hold', 'payment.refunded', 'payment.reversed'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeCallbackRecord(Request $request, array $payload, string $event, string $deliveryId): array
    {
        return [
            'received_at' => now()->toIso8601String(),
            'event' => $event,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'payload' => $payload,
            'headers' => [
                'X-EvPay-Event' => $request->header('X-EvPay-Event'),
                'X-EvPay-Delivery' => $deliveryId !== '' ? $deliveryId : null,
            ],
        ];
    }

    private function deliveryAlreadyProcessed(Order $order, string $deliveryId): bool
    {
        if ($deliveryId === '') {
            return false;
        }

        $callback = is_array($order->payment_callback) ? $order->payment_callback : [];
        $deliveries = $callback['deliveries'] ?? [];
        if (! is_array($deliveries)) {
            return false;
        }

        foreach ($deliveries as $delivery) {
            if (is_array($delivery) && ($delivery['delivery_id'] ?? null) === $deliveryId) {
                return true;
            }
        }

        return ($callback['delivery_id'] ?? null) === $deliveryId;
    }

    /**
     * @param  array<string, mixed>  $callbackRecord
     */
    private function appendCallback(Order $order, array $callbackRecord, string $deliveryId): void
    {
        $existing = is_array($order->payment_callback) ? $order->payment_callback : [];
        $deliveries = is_array($existing['deliveries'] ?? null) ? $existing['deliveries'] : [];
        $deliveries[] = $callbackRecord;

        $order->payment_callback = array_merge($existing, [
            'last' => $callbackRecord,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : ($existing['delivery_id'] ?? null),
            'deliveries' => $deliveries,
        ]);
    }

    private function storedPaymentUrl(Order $order): ?string
    {
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $url = $payload['response']['paymentUrl'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function clearStoredPaymentUrl(Order $order): void
    {
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
        if (! isset($payload['response']) || ! is_array($payload['response'])) {
            return;
        }

        $payload['response']['paymentUrl'] = null;
        $order->payment_payload = $payload;
    }

    private function chargeAmount(Order $order): float
    {
        return round((float) $order->total_amount, 2);
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @return array{
     *     order_id: int,
     *     payment_reference: string,
     *     checkout_url: string,
     *     payment_url: string,
     *     payment_status: string
     * }
     */
    private function checkoutResponse(Order $order, string $paymentUrl): array
    {
        return [
            'order_id' => (int) $order->id,
            'payment_reference' => (string) $order->payment_reference,
            'checkout_url' => $paymentUrl,
            'payment_url' => $paymentUrl,
            'payment_status' => (string) $order->payment_status,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function customerNames(?string $name): array
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return ['Customer', 'User'];
        }

        $parts = preg_split('/\s+/', $trimmed, 2) ?: [];

        return [
            $parts[0] !== '' ? $parts[0] : 'Customer',
            ($parts[1] ?? '') !== '' ? $parts[1] : 'User',
        ];
    }

    private function shouldRegenerateReference(?string $reference): bool
    {
        if (! $reference) {
            return true;
        }

        if (strlen($reference) > 20) {
            return true;
        }

        return ! preg_match('/^ORD-\d{8}-\d{3}$/', $reference);
    }

    private function generateUniquePaymentReference(): string
    {
        return DB::transaction(function () {
            $date = now()->format('Ymd');
            $today = now()->toDateString();
            $prefix = "ORD-{$date}-";

            $latestToday = Order::whereDate('created_at', $today)
                ->whereNotNull('payment_reference')
                ->where('payment_reference', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('payment_reference')
                ->value('payment_reference');

            $nextNumber = 1;
            if ($latestToday) {
                $lastPart = substr($latestToday, -3);
                if (is_numeric($lastPart)) {
                    $nextNumber = ((int) $lastPart) + 1;
                }
            }

            while ($nextNumber <= 999) {
                $reference = $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

                if (strlen($reference) > 20) {
                    break;
                }

                if (! Order::where('payment_reference', $reference)->exists()) {
                    return $reference;
                }

                $nextNumber++;
            }

            throw new RuntimeException('Unable to generate a unique payment reference.');
        });
    }
}
