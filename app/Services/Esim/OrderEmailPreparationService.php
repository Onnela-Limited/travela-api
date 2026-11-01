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

    public function unavailableReason(Order $order): ?string
    {
        if (! $this->assignment->orderIsPaid($order)) return 'Payment must be confirmed before assigning a number.';
        if ($this->assignment->orderSimType($order) !== Esim::SIM_TYPE_ESIM) return 'This action is only available for eSIM orders.';
        if (OrderCheckout::isTopUpOrder($order) || data_get($order->metadata, 'partner_bulk_invoice_id')) return 'This order does not use retail eSIM assignment.';
        $order->loadMissing(['user', 'trip', 'orderItems.bundle']);
        if (! $order->user || ! filter_var(trim((string) $order->user->email), FILTER_VALIDATE_EMAIL)) return 'The order needs a customer with a valid email address.';
        if (! $this->assignment->activationDateIsDue($order)) return 'This eSIM is scheduled for activation on '.$this->assignment->activationDateIso($order).'.';
        if (UserEsim::where('order_id', $order->id)->exists()) return 'A SIM is already assigned. Use its resend action or review its recharge status.';
        if (in_array($order->recharge_status, ['success', 'sent', 'queued', 'pending', 'in_progress', 'failed'], true)) return 'A recharge was already attempted for this order. Review it before assigning another number.';
        if (! $order->orderItems->contains(fn ($item) => $item->bundle?->sim_bundle_id)) return 'This order has no rechargeable data bundle.';
        // Do not reuse a SIM from another purchase or silently replace incomplete inventory.
        $existing = UserEsim::with('esim')->where('user_id', $order->user_id)->get();
        if ($existing->count() > 1) return 'This customer has multiple SIM assignments. Review their order links first.';
        $legacy = $existing->first();
        if ($legacy && ($legacy->order_id || ! $this->usableSim($legacy->esim))) return 'The customer already has a SIM assignment that needs review. No additional number will be allocated.';
        $linkedId = data_get($order->metadata, 'user_esim_id');
        if ($linkedId && (! $legacy || (int) $linkedId !== (int) $legacy->id)) return 'The order has an inconsistent SIM link. Review it before assigning a number.';
        return null;
    }

    public function prepareAndSend(Order $order, int $adminId): array
    {
        $lock = Cache::lock('admin-order-email-prepare:'.$order->user_id, 180);
        abort_unless($lock->get(), 409, 'This customer SIM is already being prepared. Refresh status before retrying.');
        try {
            $assignment = DB::transaction(function () use ($order, $adminId) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $reason = $this->unavailableReason($lockedOrder);
                abort_if($reason !== null, 422, $reason ?? 'Order is not eligible.');
                $result = $this->assignment->assignForPaidOrder($lockedOrder, requireQrCode: true);
                abort_unless($result['assigned'] ?? false, 409, 'No available eSIM with a phone number and QR code could be assigned. Check inventory.');
                $assigned = $result['assignment'];
                abort_unless((int) $assigned->order_id === (int) $lockedOrder->id && (int) $assigned->user_id === (int) $lockedOrder->user_id && $this->usableSim($assigned->esim), 409, 'The existing SIM link needs review. No email was sent.');
                $meta = $lockedOrder->fresh()->metadata ?? [];
                $meta['email_preparation'] = ['requested_by' => $adminId, 'requested_at' => now()->toIso8601String(), 'user_esim_id' => $assigned->id];
                $lockedOrder->update(['metadata' => $meta]);
                return $assigned;
            });

            // The carrier call runs after assignment commits. Existing recharge
            // idempotency guards remain responsible for payment/recharge handling.
            $this->recharge->rechargePaidOrder($order->fresh());
            $order->refresh();
            if ($order->recharge_status !== 'success') {
                return ['email_sent' => false, 'message' => 'A number is assigned. The data recharge is not confirmed yet, so no QR email was sent. Refresh status before retrying.'];
            }
            // A carrier callback may have sent the automatic email concurrently.
            $recent = DB::table('esim_email_attempts')->where('user_esim_id', $assignment->id)
                ->whereNotNull('sent_at')->where('created_at', '>=', now()->subMinute())->exists();
            if (! $recent) {
                abort_unless($this->email->resend($assignment, $adminId), 502, 'The number is assigned, but the email could not be confirmed as sent. Check delivery status before retrying.');
            }
            return ['email_sent' => true, 'message' => 'The number is assigned and the QR email was submitted. Refresh delivery status to confirm delivery.'];
        } finally {
            $lock->release();
        }
    }

    private function usableSim(?Esim $sim): bool
    {
        return $sim && $sim->sim_type === Esim::SIM_TYPE_ESIM
            && trim((string) $sim->msisdn) !== '' && $sim->network_id !== null
            && trim((string) $sim->qr_code_data) !== '' && ! $sim->partner_bulk_order_id;
    }
}
