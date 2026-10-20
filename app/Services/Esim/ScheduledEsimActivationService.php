<?php

namespace App\Services\Esim;

use App\Models\Esim;
use App\Models\Order;
use App\Support\OrderCheckout;

class ScheduledEsimActivationService
{
    public function __construct(
        private readonly SimAssignmentService $assignment,
        private readonly OrderRechargeService $recharge,
        private readonly EsimActivationEmailService $email,
    ) {}

    public function activateOrder(Order $order): array
    {
        if (! $order->user_id) {
            return ['status' => 'missing_customer'];
        }

        if (! $this->assignment->orderIsPaid($order)
            || OrderCheckout::isTopUpOrder($order)
            || $this->assignment->orderSimType($order) !== Esim::SIM_TYPE_ESIM
            || ! $this->assignment->activationDateIsDue($order)) {
            return ['status' => 'not_due'];
        }

        $result = $this->assignment->assignForPaidOrder($order);
        if (! ($result['assigned'] ?? false)) {
            return ['status' => $result['reason'] ?? 'waiting_for_inventory'];
        }

        $order->refresh();
        // A queued request must finish via the carrier callback, not be charged again.
        if (! in_array($order->recharge_status, ['success', 'queued', 'pending', 'in_progress'], true)) {
            $this->recharge->rechargePaidOrder($order);
            $order->refresh();
        }

        if ($order->recharge_status !== 'success') {
            return ['status' => $order->recharge_status ?? 'pending_retry'];
        }

        $sent = $this->email->sendIfEligible($result['assignment']);
        return ['status' => 'ready', 'email_sent' => $sent];
    }
}
