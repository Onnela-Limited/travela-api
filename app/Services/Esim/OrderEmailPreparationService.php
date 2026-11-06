<?php

namespace App\Services\Esim;

use App\Models\Esim;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEsim;
use App\Support\OrderCheckout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderEmailPreparationService
{
    public function __construct(
        private readonly SimAssignmentService $assignment,
        private readonly OrderRechargeService $recharge,
        private readonly EsimActivationEmailService $email,
    ) {}

    public function unavailableReason(Order $order, bool $verifiedMissing = false): ?string
    {
        if (! $this->assignment->orderIsPaid($order)) return 'Payment must be confirmed before recharging the bundle.';
        if ($this->assignment->orderSimType($order) !== Esim::SIM_TYPE_ESIM) return 'This action is only available for eSIM orders.';
        if (OrderCheckout::isTopUpOrder($order) || data_get($order->metadata, 'partner_bulk_invoice_id')) return 'This order requires its existing top-up or bulk workflow.';
        $order->loadMissing(['user', 'trip', 'orderItems.bundle']);
        if (! $order->user || ! filter_var(trim((string) $order->user->email), FILTER_VALIDATE_EMAIL)) return 'The order needs a customer with a valid email address.';
        if (! $this->assignment->activationDateIsDue($order)) return 'This eSIM is scheduled for activation on '.$this->assignment->activationDateIso($order).'.';
        if (! $verifiedMissing && $order->recharge_status !== 'success' && (in_array($order->recharge_status, ['sent', 'queued', 'pending', 'in_progress', 'pending_retry'], true)
            || $order->orderItems->contains(fn ($item) => in_array(data_get($item->metadata, 'recharge.status'), ['sent', 'queued', 'pending', 'in_progress', 'pending_retry'], true)))) {
            return 'A carrier recharge is processing or its result is uncertain. Wait for confirmation before retrying.';
        }
        if (! $order->orderItems->contains(fn ($item) => $item->bundle?->sim_bundle_id)) return 'This order has no rechargeable data bundle.';
        $assigned = UserEsim::with('esim')->where('order_id', $order->id)->get();
        if ($assigned->count() > 1) return 'This order has multiple SIM assignments. Review the target number first.';
        $current = $assigned->first();
        if ($current) {
            if ((int) $current->user_id !== (int) $order->user_id || ! $this->usableSim($current->esim)) return 'The assigned SIM needs a valid phone number and network, and must belong to this customer.';
            $linkedId = data_get($order->metadata, 'user_esim_id');
            if ($linkedId && (int) $linkedId !== (int) $current->id) return 'The order has an inconsistent SIM link. Review it before recharging.';
            return null;
        }
        if ($order->recharge_status === 'success') return 'Recharge is already successful but its SIM link is missing. Review the order before assigning another number.';
        $existing = UserEsim::with('esim')->where('user_id', $order->user_id)->get();
        if ($existing->count() > 1) return 'This customer has multiple SIM assignments. Review their order links first.';
        $legacy = $existing->first();
        if ($legacy && ($legacy->order_id || ! $this->usableSim($legacy->esim, true))) return 'The customer already has a SIM assignment that needs review. No additional number will be allocated.';
        $linkedId = data_get($order->metadata, 'user_esim_id');
        if ($linkedId && (! $legacy || (int) $linkedId !== (int) $legacy->id)) return 'The order has an inconsistent SIM link. Review it before assigning a number.';
        return null;
    }

    public function verifiedRetryReason(Order $order): ?string
    {
        if (! in_array($order->recharge_status, ['sent', 'queued', 'pending', 'in_progress', 'pending_retry', 'failed'], true)) return 'This order has no unresolved recharge to retry.';
        $reason = $this->unavailableReason($order, true);
        if ($reason) return $reason;
        if (data_get($order->metadata, 'email_preparation.notification_status') === 'sent') return 'A completed notification already exists. Review this order before retrying.';
        $latest = $this->lastAttemptAt($order);
        if ($latest && $latest->gt(now()->subMinutes(10))) return 'Wait at least 10 minutes after the last recharge attempt before recording a verified retry.';
        return null;
    }

    public function lastAttemptAt(Order $order): ?\Carbon\Carbon
    {
        $order->loadMissing('orderItems');
        // Fulfillment scans and unrelated edits are not carrier attempts.
        $dates = collect([data_get($order->metadata, 'recharge_last_attempt_at')])
            ->merge($order->orderItems->map(fn ($item) => data_get($item->metadata, 'recharge.requested_at')))
            ->filter()->map(function ($date) {
                try { return \Carbon\Carbon::parse($date); } catch (\Throwable) { return null; }
            })->filter();
        return $dates->sortByDesc(fn ($date) => $date->timestamp)->first() ?? $order->created_at;
    }

    public function retryToken(Order $order): string
    {
        $order->loadMissing('orderItems');
        return hash('sha256', json_encode([$order->id, $order->recharge_status, $order->recharge_reference,
            $order->updated_at?->format('Y-m-d H:i:s.u'), data_get($order->metadata, 'verified_recharge_retries', []),
            $order->orderItems->map(fn ($item) => [$item->id, data_get($item->metadata, 'recharge')])->all()]));
    }

    public function prepareAndSend(Order $order, int $adminId, bool $verifiedMissing = false, ?string $expectedRetryToken = null): array
    {
        $lock = Cache::lock('admin-order-email-prepare:'.$order->user_id, 180);
        abort_unless($lock->get(), 409, 'This customer bundle is already being prepared. Refresh status before retrying.');
        try {
            DB::transaction(function () use ($order, $adminId, $verifiedMissing, $expectedRetryToken) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($verifiedMissing) {
                    abort_unless(is_string($expectedRetryToken) && hash_equals($this->retryToken($lockedOrder), $expectedRetryToken), 409, 'The recharge state changed. Refresh and review it before retrying.');
                    $retryReason = $this->verifiedRetryReason($lockedOrder);
                    abort_if($retryReason !== null, 422, $retryReason ?? 'Retry is not available.');
                    $meta = $lockedOrder->metadata ?? [];
                    $meta['verified_recharge_retries'][] = ['requested_by' => $adminId, 'requested_at' => now()->toIso8601String(),
                        'verification' => 'Admin confirmed the previous bundle was not added',
                        'previous_status' => $lockedOrder->recharge_status, 'previous_reference' => $lockedOrder->recharge_reference,
                        'previous_items' => $lockedOrder->orderItems->map(fn ($item) => ['id' => $item->id, 'recharge' => data_get($item->metadata, 'recharge')])->all()];
                    foreach ($lockedOrder->orderItems as $item) {
                        if ($item->type !== 'bundle' || data_get($item->metadata, 'recharge.status') === 'success') continue;
                        $itemMeta = $item->metadata ?? [];
                        $itemMeta['recharge']['status'] = 'failed';
                        $item->update(['metadata' => $itemMeta]);
                    }
                    $meta['recharge_last_attempt_at'] = now()->toIso8601String();
                    $lockedOrder->update(['metadata' => $meta, 'recharge_status' => 'failed']);
                }
                $reason = $this->unavailableReason($lockedOrder, $verifiedMissing);
                abort_if($reason !== null, 422, $reason ?? 'Order is not eligible.');
                $assigned = UserEsim::with('esim')->where('order_id', $lockedOrder->id)->first();
                $newNumber = ! $assigned;
                if (! $assigned) {
                    $result = $this->assignment->assignForPaidOrder($lockedOrder, requireQrCode: true);
                    abort_unless($result['assigned'] ?? false, 409, 'No available eSIM with a phone number and QR code could be assigned. Check inventory.');
                    $assigned = $result['assignment'];
                }
                abort_unless((int) $assigned->order_id === (int) $lockedOrder->id && (int) $assigned->user_id === (int) $lockedOrder->user_id && $this->usableSim($assigned->esim, $newNumber), 409, 'The existing SIM link needs review. No recharge was requested.');
                $meta = $lockedOrder->fresh()->metadata ?? [];
                $meta['user_esim_id'] = $assigned->id;
                $meta['esim_id'] = $assigned->esim_id;
                $meta['msisdn'] = $assigned->esim->msisdn;
                if (! isset($meta['email_preparation']['notification_status'])) {
                    $meta['email_preparation'] = ['requested_by' => $adminId, 'requested_at' => now()->toIso8601String(),
                        'user_esim_id' => $assigned->id, 'include_qr' => $newNumber, 'notification_status' => 'pending'];
                }
                $lockedOrder->update(['metadata' => $meta]);
            });
            $order->refresh();
            if (data_get($order->metadata, 'email_preparation.notification_status') === 'sent') {
                return ['email_sent' => true, 'message' => 'This order recharge and its confirmation email were already completed. Check the delivery history; no bundle was loaded again.'];
            }
            if ($order->recharge_status !== 'success') {
                $this->recharge->rechargePaidOrder($order);
                $order->refresh();
            }
            if ($order->recharge_status !== 'success') {
                return ['email_sent' => false, 'message' => 'The number is assigned. Recharge is not confirmed successful yet, so no confirmation email was sent. A successful carrier callback will trigger the email. Refresh status before retrying.'];
            }
            abort_unless($this->sendPendingNotification($order), 502, 'Recharge succeeded, but the email could not be confirmed as sent. Refresh status and retry the email; the bundle will not be recharged again.');
            return ['email_sent' => true, 'message' => 'The bundle recharge is confirmed and the customer notification email was submitted. This order will not be recharged again.'];
        } finally {
            $lock->release();
        }
    }

    public function sendPendingNotification(Order $order): bool
    {
        $lock = Cache::lock('order-recharge-notification:'.$order->id, 120);
        if (! $lock->get()) return false;
        try {
            $order->refresh();
            $intent = data_get($order->metadata, 'email_preparation');
            if ($order->recharge_status !== 'success' || ! is_array($intent)) return false;
            if (($intent['notification_status'] ?? null) === 'sent') return true;
            if (($intent['notification_status'] ?? null) !== 'pending') return false;
            $assignment = UserEsim::where('order_id', $order->id)->where('user_id', $order->user_id)->find($intent['user_esim_id'] ?? 0);
            if (! $assignment) return false;
            if (! $this->email->sendRechargeConfirmation($assignment, $order, (int) $intent['requested_by'], (bool) ($intent['include_qr'] ?? false))) return false;
            DB::transaction(function () use ($order) {
                $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $meta = $fresh->metadata;
                $meta['email_preparation']['notification_status'] = 'sent';
                $meta['email_preparation']['sent_at'] = now()->toIso8601String();
                $fresh->update(['metadata' => $meta]);
            });
            return true;
        } finally {
            $lock->release();
        }
    }

    private function usableSim(?Esim $sim, bool $requireQr = false): bool
    {
        return $sim && $sim->sim_type === Esim::SIM_TYPE_ESIM
            && trim((string) $sim->msisdn) !== '' && $sim->network_id !== null
            && (! $requireQr || trim((string) $sim->qr_code_data) !== '') && ! $sim->partner_bulk_order_id;
    }
}
