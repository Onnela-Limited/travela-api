<?php

namespace App\Services\Esim;

use App\Mail\EsimRechargeConfirmationMail;
use App\Models\Order;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OrderTopupNotificationService
{
    public function sendIfConfirmed(Order $order): void
    {
        if (data_get($order->metadata, 'checkoutMode') !== 'topup') return;
        $lock = Cache::lock('order-topup-email:'.$order->id, 120);
        if (! $lock->get()) return;
        try {
            $order->refresh();
            if ($order->recharge_status !== 'success' || data_get($order->metadata, 'topup_notification.status') ||
                data_get($order->metadata, 'email_preparation')) return;
            $assignmentId = data_get($order->metadata, 'recharge.user_esim_id') ?? data_get($order->metadata, 'user_esim_id');
            $assignment = UserEsim::with(['user', 'esim'])->where('user_id', $order->user_id)->find($assignmentId);
            if (! $assignment?->esim?->msisdn || ! filter_var($assignment->user?->email, FILTER_VALIDATE_EMAIL)) return;
            $this->record($order, 'sending');
            try {
                $order->loadMissing('orderItems.bundle');
                $sent = Mail::to($assignment->user->email)->send(new EsimRechargeConfirmationMail(
                    customerName: $assignment->user->name, msisdn: $assignment->esim->msisdn, iccid: null,
                    orderReference: $order->recharge_reference,
                    bundleName: $order->orderItems->where('type', 'bundle')->map(fn ($item) => $item->bundle_name ?: $item->bundle?->name)->implode(', '),
                    dashboardUrl: RechargeDashboardLink::forNumber($assignment->esim->msisdn, $order->recharge_reference),
                ));
                $this->record($order, $sent !== null || Mail::isFake() ? 'sent' : 'unconfirmed');
            } catch (Throwable $e) {
                $this->record($order, 'unconfirmed');
                Log::error('Top-up notification could not be confirmed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        } finally {
            $lock->release();
        }
    }

    private function record(Order $order, string $status): void
    {
        DB::transaction(function () use ($order, $status) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $meta = $fresh->metadata ?? [];
            $meta['topup_notification'] = ['status' => $status, 'updated_at' => now()->toIso8601String()];
            $fresh->update(['metadata' => $meta]);
        });
    }
}
