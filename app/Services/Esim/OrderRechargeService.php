<?php

namespace App\Services\Esim;

use App\Models\Bundle;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderRechargeService
{
    private const FULFILLED_STATUSES = ['success', 'sent', 'queued'];

    private const TERMINAL_SUCCESS_STATUSES = ['success'];

    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly VodacomBalanceService $balances,
    ) {}

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{processed:int, skipped:int, failed:int, errors:array<int, string>, recharge_status?: string}
     */
    public function rechargePaidOrder(Order $order, ?array $evpayContext = null): array
    {
        return $this->fulfillOrder($order, $evpayContext);
    }

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{processed:int, skipped:int, failed:int, errors:array<int, string>, recharge_status?: string}
     */
    public function fulfillOrder(Order $order, ?array $evpayContext = null): array
    {
        $order->refresh();
        $order->loadMissing(['orderItems.bundle']);

        $paymentId = $evpayContext['payment_id'] ?? $order->gateway_payment_id;
        $transactionRef = $evpayContext['transaction_reference'] ?? $order->payment_reference;

        if (! $this->orderIsPaid($order)) {
            return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        }

        if ($this->orderRechargeIsSuccessful($order)) {
            Log::info('Order recharge skipped: already successful', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'recharge_reference' => $order->recharge_reference,
                'recharge_transaction_id' => $order->recharge_transaction_id,
                'recharge_status' => $order->recharge_status,
            ]);

            return [
                'processed' => 0,
                'skipped' => $this->rechargeableItems($order)->count(),
                'failed' => 0,
                'errors' => [],
                'recharge_status' => 'success',
            ];
        }

        if ($this->shouldSkipRechargeForPayment($order, $paymentId, $transactionRef)) {
            Log::info('Order recharge skipped: already fulfilled for this payment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'payment_id' => $paymentId,
                'transaction_reference' => $transactionRef,
                'recharge_status' => $order->recharge_status,
            ]);

            return [
                'processed' => 0,
                'skipped' => $this->rechargeableItems($order)->count(),
                'failed' => 0,
                'errors' => [],
                'recharge_status' => 'success',
            ];
        }

        $assignment = $this->resolveUserEsimForOrder($order);
        if (! $assignment?->esim) {
            $userEsimCount = UserEsim::where('user_id', $order->user_id)->count();
            $message = 'No user eSIM assignment with MSISDN found for this order user.';
            Log::error('Order recharge failed: missing user eSIM', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'payment_id' => $paymentId,
                'transaction_reference' => $transactionRef,
                'user_esim_assignments_count' => $userEsimCount,
                'order_metadata_keys' => array_keys($this->orderMetadata($order)),
                'hint' => 'Assign an eSIM via admin user-esims, or pass msisdn/esim_id/user_esim_id on the order payload.',
            ]);
            $this->setOrderRechargeStatus($order, 'pending_esim', $message, $paymentId, $transactionRef);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$message],
                'recharge_status' => 'pending_esim',
            ];
        }

        $esim = $assignment->esim;
        if (! $esim->msisdn || $esim->network_id === null) {
            $message = 'Assigned eSIM is missing msisdn or network_id.';
            Log::error('Order recharge failed: incomplete eSIM inventory record', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'user_esim_id' => $assignment->id,
                'esim_id' => $esim->id,
                'msisdn' => $esim->msisdn,
                'network_id' => $esim->network_id,
            ]);
            $this->setOrderRechargeStatus($order, 'pending_esim', $message, $paymentId, $transactionRef, $assignment->id);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$message],
                'recharge_status' => 'pending_esim',
            ];
        }

        $this->linkOrderToUserEsim($order, $assignment, $paymentId, $transactionRef);
        $this->setOrderRechargeStatus($order, 'in_progress', null, $paymentId, $transactionRef, $assignment->id);

        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'attempts' => []];

        foreach ($this->rechargeableItems($order) as $item) {
            if ($this->itemAlreadyFulfilled($item)) {
                $result['skipped']++;

                continue;
            }

            $bundle = $item->bundle;
            if (! $bundle?->sim_bundle_id) {
                $message = "Order item {$item->id} has no Vodacom product_id (bundles.sim_bundle_id).";
                Log::error('Order recharge validation failed: missing product_id', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'bundle_id' => $item->bundle_id,
                    'user_id' => $order->user_id,
                    'user_esim_id' => $assignment->id,
                ]);
                $this->markItemRechargeFailed($item, $message, null, $order, $assignment);
                $result['failed']++;
                $result['errors'][] = $message;

                continue;
            }

            $reference = $this->generateRechargeReference($order, $item);
            $payload = $this->buildRechargePayload($esim, $bundle, $item, $order, $reference);
            $validation = $this->validateRechargePayload($payload);

            if ($validation !== []) {
                $message = 'Recharge payload validation failed: missing or invalid '.implode(', ', $validation);
                Log::error('Order recharge validation failed', $this->rechargeLogContext(
                    $order,
                    $assignment,
                    $item,
                    $bundle,
                    $payload,
                    ['validation_errors' => $validation]
                ));
                $this->markItemRechargeFailed($item, $message, $payload, $order, $assignment);
                $result['failed']++;
                $result['errors'][] = $message;

                continue;
            }

            $rechargeLog = $this->rechargePayloadLog($order, $assignment, $payload);

            Log::info('Vodacom recharge request payload', $rechargeLog);

            try {
                $response = $this->vodacom->post('/api/recharge', [], $payload, $rechargeLog);
                $body = $response->json();
                $responseBody = is_array($body) ? $body : ['raw' => (string) $response->body()];
                $httpStatus = $response->status();
                $interpreted = VodacomRechargePayload::unwrapResponse($responseBody);
                $vodacomStatus = VodacomRechargePayload::interpretStatus($interpreted, $httpStatus);

                $this->logRechargeResponse($rechargeLog, $httpStatus, $responseBody, $vodacomStatus);

                $transactionId = VodacomRechargePayload::transactionIdFrom($interpreted);

                if (VodacomRechargePayload::isSuccessStatus($interpreted, $httpStatus)) {
                    $this->markRechargeSuccess($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'success',
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['processed']++;
                } elseif (VodacomRechargePayload::isFailedStatus($interpreted, $httpStatus)) {
                    $this->markRechargeFailed($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'failed',
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['failed']++;
                    $result['errors'][] = $this->vodacomFailureMessage($item->id, $httpStatus, $responseBody);
                    $result['attempts'][] = $this->rechargeAttemptRecord($item->id, $payload, $httpStatus, $responseBody);
                } elseif ($response->successful() && in_array($vodacomStatus, ['queued', 'pending'], true)) {
                    $this->persistOrderRechargeInProgress($order, $reference, $transactionId, $responseBody, $vodacomStatus, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        $vodacomStatus,
                        $httpStatus,
                        $payload,
                        $responseBody
                    ));
                    $result['processed']++;
                } else {
                    $this->markRechargeFailed($order, $reference, $transactionId, $responseBody, $httpStatus);
                    $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                        $reference,
                        $transactionId,
                        'pending_retry',
                        $httpStatus,
                        $payload,
                        $responseBody,
                        $responseBody['error'] ?? 'Unexpected Vodacom response'
                    ));
                    $result['failed']++;
                    $result['errors'][] = $this->vodacomFailureMessage($item->id, $httpStatus, $responseBody, $vodacomStatus);
                    $result['attempts'][] = $this->rechargeAttemptRecord($item->id, $payload, $httpStatus, $responseBody);
                }
            } catch (\Throwable $e) {
                Log::error('Order recharge request exception', array_merge(
                    $this->rechargeLogContext($order, $assignment, $item, $bundle, $payload),
                    ['exception' => $e->getMessage()]
                ));

                $this->persistItemRecharge($item, [
                    'reference' => $reference,
                    'status' => 'pending_retry',
                    'requested_at' => now()->toIso8601String(),
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ]);

                $result['failed']++;
                $result['errors'][] = $e->getMessage();
            }
        }

        if ($result['processed'] > 0 && $esim->msisdn) {
            $this->requestBalanceRefreshAfterRecharge($order, $esim);
        }

        $rechargeStatus = $this->deriveOrderRechargeStatus($order, $result);
        $this->recordFulfillmentSummary($order, $result, $rechargeStatus, $paymentId, $transactionRef, $assignment->id);
        $result['recharge_status'] = $rechargeStatus;

        return $result;
    }

    /**
     * Persist Vodacom's async recharge callback onto the matching order (if any).
     *
     * @param  array<string, mixed>  $raw
     * @return array{order_id: int|null, recharge_status: string|null}
     */
    public function applyRechargeCallback(array $raw): array
    {
        $payload = VodacomRechargePayload::unwrapResponse($raw);
        $reference = isset($payload['reference']) && is_string($payload['reference']) && $payload['reference'] !== ''
            ? VodacomRechargePayload::formatReference($payload['reference'])
            : null;
        $transactionId = VodacomRechargePayload::transactionIdFrom($payload, false);
        $mappedStatus = VodacomRechargePayload::interpretStatus($payload, 200);

        $order = $this->findOrderForCallback($payload);
        if (! $order) {
            Log::info('Vodacom recharge callback: no matching order', [
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'msisdn' => $payload['msisdn'] ?? null,
            ]);

            return ['order_id' => null, 'recharge_status' => null];
        }

        DB::transaction(function () use ($order, $raw, $reference, $transactionId, $mappedStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $meta = $this->orderMetadata($order);
            $meta['recharge_callback'] = $raw;
            $order->recharge_response = $raw;
            $order->recharge_http_status = $order->recharge_http_status ?: 200;

            if ($reference && ! $order->recharge_reference) {
                $order->recharge_reference = $reference;
            }
            if ($transactionId && ! $order->recharge_transaction_id) {
                $order->recharge_transaction_id = $transactionId;
            }

            if ($order->recharge_status !== 'success') {
                if (in_array($mappedStatus, ['success', 'failed', 'queued', 'pending'], true)) {
                    $order->recharge_status = $mappedStatus;
                }
                if ($mappedStatus === 'success') {
                    $order->recharge_completed_at = now();
                }
            }

            $this->syncRechargeMetadata(
                $order,
                $order->recharge_status ?? $mappedStatus,
                $reference ?? $order->recharge_reference,
                $transactionId ?? $order->recharge_transaction_id,
                $raw,
            );
            $order->save();
        });

        $order->refresh()->loadMissing('orderItems');
        foreach ($order->orderItems as $item) {
            $existing = $this->itemMetadataArray($item)['recharge'] ?? [];
            if (! is_array($existing)) {
                $existing = [];
            }
            $itemRef = $existing['reference'] ?? $existing['recharge_reference'] ?? null;
            if ($reference && is_string($itemRef) && $itemRef !== '' && $itemRef !== $reference) {
                continue;
            }

            $this->persistItemRecharge($item, $this->buildItemRechargeRecord(
                $reference ?? (is_string($itemRef) ? $itemRef : ''),
                $transactionId ?? ($existing['recharge_transaction_id'] ?? null),
                $order->recharge_status ?? $mappedStatus,
                200,
                is_array($existing['payload'] ?? null) ? $existing['payload'] : [],
                $raw,
            ));
        }

        if (($order->recharge_status === 'success' || $mappedStatus === 'success')) {
            $msisdn = $payload['msisdn'] ?? $this->orderMetadata($order)['msisdn'] ?? null;
            if (is_string($msisdn) && $msisdn !== '') {
                $esim = Esim::findByMsisdn($msisdn);
                if ($esim) {
                    $this->requestBalanceRefreshAfterRecharge($order, $esim);
                }
            }
        }

        if ($order->recharge_status === 'success' && data_get($order->metadata, 'email_preparation.notification_status') === 'pending') {
            DB::afterCommit(function () use ($order) {
                try {
                    app(OrderEmailPreparationService::class)->sendPendingNotification($order);
                } catch (\Throwable $e) {
                    Log::warning('Recharge succeeded but customer notification needs retry', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            });
        }

        return [
            'order_id' => $order->id,
            'recharge_status' => $order->recharge_status,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrderForCallback(array $payload): ?Order
    {
        $reference = isset($payload['reference']) && is_string($payload['reference']) && $payload['reference'] !== ''
            ? VodacomRechargePayload::formatReference($payload['reference'])
            : null;
        $transactionId = VodacomRechargePayload::transactionIdFrom($payload, false);

        if ($reference) {
            $order = Order::query()->where('recharge_reference', $reference)->latest('id')->first();
            if ($order) {
                return $order;
            }
        }

        if ($transactionId) {
            $order = Order::query()->where('recharge_transaction_id', $transactionId)->latest('id')->first();
            if ($order) {
                return $order;
            }
        }

        if ($reference) {
            $item = OrderItem::query()
                ->where('metadata->recharge->reference', $reference)
                ->latest('id')
                ->first();
            if ($item) {
                return $item->order;
            }
        }

        return null;
    }

    private function requestBalanceRefreshAfterRecharge(Order $order, Esim $esim): void
    {
        try {
            $balanceResult = $this->balances->requestBalancesForMsisdn($esim->msisdn);
            $meta = $this->orderMetadata($order);
            $meta['balance_poll_started_at'] = now()->toIso8601String();
            $meta['balance_request_status'] = $balanceResult['status'] ?? null;
            $order->metadata = $meta;
            $order->save();

            Log::info('Balance refresh requested after recharge', [
                'order_id' => $order->id,
                'msisdn' => $esim->msisdn,
                'balance_request_status' => $balanceResult['status'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Balance refresh after recharge failed', [
                'order_id' => $order->id,
                'msisdn' => $esim->msisdn,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function orderIsPaid(Order $order): bool
    {
        return $order->payment_status === 'paid' || $order->status === 'paid';
    }

    private function shouldSkipRechargeForPayment(Order $order, ?string $paymentId, ?string $transactionRef): bool
    {
        if ($this->orderRechargeIsSuccessful($order)) {
            return true;
        }

        $meta = $this->orderMetadata($order);
        $storedPaymentId = $meta['recharge_evpay_payment_id'] ?? $order->gateway_payment_id;
        $storedRef = $meta['recharge_evpay_transaction_reference'] ?? $order->payment_reference;

        if ($paymentId && $storedPaymentId === $paymentId && $this->allRechargeableItemsFulfilled($order)) {
            return true;
        }

        if ($transactionRef && $storedRef === $transactionRef && $this->allRechargeableItemsFulfilled($order)) {
            return true;
        }

        return false;
    }

    private function orderRechargeIsSuccessful(Order $order): bool
    {
        return $order->recharge_status === 'success';
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function markRechargeSuccess(
        Order $order,
        string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => 'success',
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_response' => $responseBody,
                'recharge_completed_at' => now(),
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, 'success', $rechargeReference, $transactionId, $responseBody);
            $order->save();

            Log::info('Order recharge marked successful', [
                'order_id' => $order->id,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_status' => 'success',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function markRechargeFailed(
        Order $order,
        ?string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => 'failed',
                'recharge_reference' => $rechargeReference ?? $order->recharge_reference,
                'recharge_transaction_id' => $transactionId ?? $order->recharge_transaction_id,
                'recharge_response' => $responseBody,
                'recharge_completed_at' => null,
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, 'failed', $rechargeReference, $transactionId, $responseBody);
            $order->save();

            Log::warning('Order recharge marked failed', [
                'order_id' => $order->id,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_status' => 'failed',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function persistOrderRechargeInProgress(
        Order $order,
        string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
        string $status,
        int $httpStatus,
    ): void {
        DB::transaction(function () use ($order, $rechargeReference, $transactionId, $responseBody, $status, $httpStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status === 'success') {
                return;
            }

            $order->fill([
                'recharge_status' => $status,
                'recharge_reference' => $rechargeReference,
                'recharge_transaction_id' => $transactionId,
                'recharge_response' => $responseBody,
                'recharge_http_status' => $httpStatus,
            ]);
            $this->syncRechargeMetadata($order, $status, $rechargeReference, $transactionId, $responseBody);
            $order->save();
        });
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function syncRechargeMetadata(
        Order $order,
        string $status,
        ?string $rechargeReference,
        ?string $transactionId,
        array $responseBody,
    ): void {
        $meta = $this->orderMetadata($order);
        $meta['recharge_status'] = $status;
        if ($rechargeReference) {
            $meta['recharge_reference'] = $rechargeReference;
        }
        if ($transactionId) {
            $meta['recharge_transaction_id'] = $transactionId;
        }
        $meta['recharge_response'] = $responseBody;
        $order->metadata = $meta;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     * @return array<string, mixed>
     */
    private function buildItemRechargeRecord(
        string $reference,
        ?string $transactionId,
        string $status,
        int $httpStatus,
        array $payload,
        array $responseBody,
        ?string $error = null,
    ): array {
        $record = [
            'reference' => $reference,
            'recharge_reference' => $reference,
            'recharge_transaction_id' => $transactionId,
            'status' => $status,
            'requested_at' => now()->toIso8601String(),
            'http_status' => $httpStatus,
            'payload' => $payload,
            'response' => $responseBody,
            'recharge_response' => $responseBody,
        ];

        if ($error) {
            $record['error'] = $error;
        }

        return $record;
    }

    private function allRechargeableItemsFulfilled(Order $order): bool
    {
        $items = $this->rechargeableItems($order);
        if ($items->isEmpty()) {
            return false;
        }

        return $items->every(fn (OrderItem $item) => $this->itemAlreadyFulfilled($item));
    }

    /**
     * Resolve SIM for recharge: order metadata → user assignment → auto-link unassigned inventory.
     */
    private function resolveUserEsimForOrder(Order $order): ?UserEsim
    {
        if (! $order->user_id) {
            return null;
        }

        $meta = $this->orderMetadata($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::query()
                ->where('id', $meta['user_esim_id'])
                ->where('user_id', $order->user_id)
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['esim_id'])) {
            $assignment = UserEsim::query()
                ->where('user_id', $order->user_id)
                ->where('esim_id', $meta['esim_id'])
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['msisdn']) && is_string($meta['msisdn'])) {
            $assignment = $this->resolveOrAssignByMsisdn($order->user_id, $meta['msisdn'], $order);
            if ($assignment) {
                return $assignment;
            }
        }

        if (! empty($meta['recharge']['user_esim_id'])) {
            $assignment = UserEsim::query()
                ->where('id', $meta['recharge']['user_esim_id'])
                ->where('user_id', $order->user_id)
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        return UserEsim::query()
            ->where('user_id', $order->user_id)
            ->with('esim')
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->orderBy('id')
            ->first();
    }

    private function resolveOrAssignByMsisdn(int $userId, string $msisdn, ?Order $order = null): ?UserEsim
    {
        $esim = Esim::findByMsisdn($msisdn);
        if (! $esim) {
            return null;
        }

        $owned = UserEsim::query()
            ->where('user_id', $userId)
            ->where('esim_id', $esim->id)
            ->with(['esim', 'bundle'])
            ->first();
        if ($owned) {
            if ($order) {
                return $this->esimOrderLink->linkAssignmentToOrder($owned, $order);
            }

            return $owned;
        }

        $taken = UserEsim::where('esim_id', $esim->id)->exists();
        if ($taken) {
            Log::warning('Order recharge: MSISDN on order belongs to another user', [
                'user_id' => $userId,
                'msisdn' => $esim->msisdn,
                'esim_id' => $esim->id,
            ]);

            return null;
        }

        $assignment = DB::transaction(function () use ($esim, $userId) {
            $locked = Esim::whereKey($esim->id)->lockForUpdate()->firstOrFail();
            if ($locked->partner_bulk_order_id || UserEsim::where('esim_id', $locked->id)->exists()) return null;
            $assignment = UserEsim::create(['user_id' => $userId, 'esim_id' => $locked->id]);
            $locked->update(['status' => 'MANAGED']);
            return $assignment;
        });
        if (! $assignment) return null;

        if ($order) {
            return $this->esimOrderLink->linkAssignmentToOrder($assignment, $order);
        }

        return $this->esimOrderLink->linkAssignmentFromLatestPaidOrder($assignment);
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function rechargeableItems(Order $order)
    {
        return $order->orderItems
            ->filter(fn (OrderItem $item) => $item->type === 'bundle')
            ->filter(fn (OrderItem $item) => $item->bundle_id !== null)
            ->values();
    }

    private function itemAlreadyFulfilled(OrderItem $item): bool
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if ($order && $this->orderRechargeIsSuccessful($order)) {
            return true;
        }

        $recharge = $this->itemMetadataArray($item)['recharge'] ?? [];

        if (! is_array($recharge)) {
            return false;
        }

        $status = $recharge['status'] ?? null;
        $reference = $recharge['reference'] ?? $recharge['recharge_reference'] ?? null;

        return $reference && in_array($status, self::TERMINAL_SUCCESS_STATUSES, true);
    }

    /**
     * product_id comes from bundles.sim_bundle_id (Vodacom catalog id for the purchased bundle).
     *
     * @return array<string, string|int>|null
     */
    private function buildRechargePayload(
        Esim $esim,
        Bundle $bundle,
        OrderItem $item,
        Order $order,
        string $reference,
    ): array {
        return VodacomRechargePayload::normalize([
            'msisdn' => $esim->msisdn,
            'network_id' => (int) $esim->network_id,
            'product_id' => (int) $bundle->sim_bundle_id,
            'reference' => $reference,
        ]);
    }

    /**
     * @return list<string> Field names that failed validation (empty = valid).
     */
    private function validateRechargePayload(?array $payload): array
    {
        if ($payload === null) {
            return ['payload'];
        }

        $errors = [];

        $msisdn = is_string($payload['msisdn'] ?? null) ? $payload['msisdn'] : '';
        if ($msisdn === '' || ! preg_match('/^\d{9,15}$/', $msisdn)) {
            $errors[] = 'msisdn';
        }

        if (! isset($payload['network_id']) || ! is_numeric($payload['network_id'])) {
            $errors[] = 'network_id';
        }

        if (empty($payload['product_id']) || ! is_numeric($payload['product_id'])) {
            $errors[] = 'product_id';
        }

        if (empty($payload['reference']) || ! is_string($payload['reference'])) {
            $errors[] = 'reference';
        }

        return $errors;
    }

    private function resolveAirtimeAmount(OrderItem $item, Bundle $bundle, Order $order): ?string
    {
        $meta = is_array($bundle->metadata) ? $bundle->metadata : [];
        foreach (['vodacom_airtime_amount', 'airtime_amount', 'recharge_amount'] as $key) {
            if (isset($meta[$key]) && $meta[$key] !== '' && $meta[$key] !== null) {
                return $this->formatAirtimeAmount($meta[$key]);
            }
        }

        if ($bundle->price_tzs !== null && (int) $bundle->price_tzs >= 1) {
            return $this->formatAirtimeAmount($bundle->price_tzs);
        }

        $byProduct = config('services.vodacom_sim.recharge_airtime_by_product_id', []);
        $productId = (int) $bundle->sim_bundle_id;
        if (isset($byProduct[$productId])) {
            return $this->formatAirtimeAmount($byProduct[$productId]);
        }
        if (isset($byProduct[(string) $productId])) {
            return $this->formatAirtimeAmount($byProduct[(string) $productId]);
        }

        return null;
    }

    private function formatAirtimeAmount(mixed $value): string
    {
        return VodacomRechargePayload::formatAirtimeAmount($value);
    }

    private function generateRechargeReference(Order $order, OrderItem $item): string
    {
        $recharge = $this->itemMetadataArray($item)['recharge'] ?? [];
        $status = is_array($recharge) ? ($recharge['status'] ?? null) : null;
        $existing = is_array($recharge) ? ($recharge['reference'] ?? null) : null;

        if (is_string($existing) && $existing !== '' && in_array($status, ['success', 'sent', 'queued'], true)) {
            return VodacomRechargePayload::formatReference($existing);
        }

        $seed = "{$order->id}:{$item->id}";
        if (in_array($status, ['failed', 'pending_retry'], true)) {
            $seed .= ':'.microtime(true);
        }

        return VodacomRechargePayload::generateReference($order->id, $item->id, $seed);
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function vodacomFailureMessage(int $itemId, int $httpStatus, array $responseBody, ?string $vodacomStatus = null): string
    {
        $detail = $responseBody['error']
            ?? $responseBody['message']
            ?? $responseBody['Message']
            ?? null;

        $message = "Vodacom recharge failed for item {$itemId} (HTTP {$httpStatus}";
        if ($vodacomStatus) {
            $message .= ", status {$vodacomStatus}";
        }
        $message .= ')';
        if (is_string($detail) && $detail !== '') {
            $message .= ': '.$detail;
        }

        return $message;
    }

    /**
     * @param  array<string, string|int>  $payload
     * @param  array<string, mixed>  $responseBody
     * @return array<string, mixed>
     */
    private function rechargeAttemptRecord(int $itemId, array $payload, int $httpStatus, array $responseBody): array
    {
        return [
            'order_item_id' => $itemId,
            'payload' => $payload,
            'http_status' => $httpStatus,
            'vodacom_response' => $responseBody,
        ];
    }

    /**
     * @param  array<string, string|int>  $payload
     * @return array<string, mixed>
     */
    private function rechargePayloadLog(Order $order, UserEsim $assignment, array $payload): array
    {
        return [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'user_esim_id' => $assignment->id,
            'msisdn' => $payload['msisdn'] ?? null,
            'network_id' => $payload['network_id'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'airtime_amount' => $payload['airtime_amount'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $rechargeLog
     * @param  array<string, mixed>  $responseBody
     */
    private function logRechargeResponse(
        array $rechargeLog,
        int $httpStatus,
        array $responseBody,
        string $vodacomStatus,
    ): void {
        $context = array_merge($rechargeLog, [
            'http_status' => $httpStatus,
            'vodacom_status' => $vodacomStatus,
            'response_body' => $responseBody,
        ]);

        if ($httpStatus >= 200 && $httpStatus < 300 && in_array($vodacomStatus, ['success', 'queued', 'pending'], true)) {
            Log::info('Vodacom recharge response received', $context);
        } else {
            Log::warning('Vodacom recharge response received', $context);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function rechargeLogContext(
        Order $order,
        UserEsim $assignment,
        OrderItem $item,
        Bundle $bundle,
        ?array $payload,
        array $extra = [],
    ): array {
        return array_merge([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'user_esim_id' => $assignment->id,
            'order_item_id' => $item->id,
            'bundle_id' => $bundle->id,
            'msisdn' => $payload['msisdn'] ?? $assignment->esim?->msisdn,
            'network_id' => $payload['network_id'] ?? $assignment->esim?->network_id,
            'product_id' => $payload['product_id'] ?? $bundle->sim_bundle_id,
            'reference' => $payload['reference'] ?? null,
            'airtime_amount' => $payload['airtime_amount'] ?? null,
            'request_payload' => $payload,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function markItemRechargeFailed(
        OrderItem $item,
        string $message,
        ?array $payload,
        Order $order,
        UserEsim $assignment,
    ): void {
        $this->persistItemRecharge($item, [
            'reference' => $payload['reference'] ?? $this->generateRechargeReference($order, $item),
            'status' => 'pending_retry',
            'requested_at' => now()->toIso8601String(),
            'payload' => $payload,
            'error' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $recharge
     */
    private function persistItemRecharge(OrderItem $item, array $recharge): void
    {
        $meta = $this->itemMetadataArray($item);
        $meta['recharge'] = $recharge;
        $item->metadata = $meta;
        $item->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemMetadataArray(OrderItem $item): array
    {
        $raw = $item->getAttributes()['metadata'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $item->metadata;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderMetadata(Order $order): array
    {
        $meta = $order->metadata;

        return is_array($meta) ? $meta : [];
    }

    private function linkOrderToUserEsim(
        Order $order,
        UserEsim $assignment,
        ?string $paymentId,
        ?string $transactionRef,
    ): void {
        $this->esimOrderLink->linkAssignmentToOrder($assignment, $order);

        $meta = $this->orderMetadata($order);
        $meta['user_esim_id'] = $assignment->id;
        $meta['esim_id'] = $assignment->esim_id;
        if ($assignment->esim?->msisdn) {
            $meta['msisdn'] = $assignment->esim->msisdn;
        }
        $order->metadata = $meta;
        $order->save();
    }

    private function setOrderRechargeStatus(
        Order $order,
        string $status,
        ?string $error,
        ?string $paymentId,
        ?string $transactionRef,
        ?int $userEsimId = null,
    ): void {
        DB::transaction(function () use ($order, $status, $error, $paymentId, $transactionRef, $userEsimId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->recharge_status !== 'success') {
                $order->recharge_status = $status;
            }

            $meta = $this->orderMetadata($order);
            $meta['recharge_status'] = $order->recharge_status ?? $status;
            if ($error) {
                $meta['recharge_error'] = $error;
            }
            if ($paymentId) {
                $meta['recharge_evpay_payment_id'] = $paymentId;
            }
            if ($transactionRef) {
                $meta['recharge_evpay_transaction_reference'] = $transactionRef;
            }
            if ($userEsimId) {
                $meta['recharge'] = array_merge(is_array($meta['recharge'] ?? null) ? $meta['recharge'] : [], [
                    'user_esim_id' => $userEsimId,
                ]);
            }
            $meta['recharge_last_attempt_at'] = now()->toIso8601String();
            $order->metadata = $meta;
            $order->save();
        });
    }

    /**
     * @param  array{processed:int, skipped:int, failed:int, errors:array<int, string>}  $result
     */
    private function deriveOrderRechargeStatus(Order $order, array $result): string
    {
        $order->refresh();

        if ($order->recharge_status === 'in_progress' && $result['failed'] > 0) {
            return 'pending_retry';
        }

        if ($order->recharge_status) {
            return $order->recharge_status;
        }

        if ($result['failed'] > 0) {
            return 'pending_retry';
        }

        if ($result['processed'] > 0) {
            return 'success';
        }

        return 'pending_retry';
    }

    /**
     * @param  array{processed:int, skipped:int, failed:int, errors:array<int, string>}  $result
     */
    private function recordFulfillmentSummary(
        Order $order,
        array $result,
        string $rechargeStatus,
        ?string $paymentId,
        ?string $transactionRef,
        ?int $userEsimId,
    ): void {
        DB::transaction(function () use ($order, $result, $rechargeStatus, $paymentId, $transactionRef, $userEsimId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->recharge_status || ($order->recharge_status === 'in_progress' && $rechargeStatus === 'pending_retry')) {
                $order->recharge_status = $rechargeStatus;
            }

            $meta = $this->orderMetadata($order);
            $meta['recharge_status'] = $order->recharge_status ?? $rechargeStatus;
            $meta['fulfillment'] = [
                'last_run_at' => now()->toIso8601String(),
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ];
            if ($paymentId) {
                $meta['recharge_evpay_payment_id'] = $paymentId;
            }
            if ($transactionRef) {
                $meta['recharge_evpay_transaction_reference'] = $transactionRef;
            }
            if ($userEsimId) {
                $meta['recharge'] = array_merge(is_array($meta['recharge'] ?? null) ? $meta['recharge'] : [], [
                    'user_esim_id' => $userEsimId,
                ]);
            }
            unset($meta['recharge_error']);
            if ($rechargeStatus === 'pending_retry' || $rechargeStatus === 'pending_esim') {
                $meta['recharge_error'] = $result['errors'][0] ?? $meta['recharge_error'] ?? null;
            }
            $order->metadata = $meta;
            $order->save();
        });
    }
}
