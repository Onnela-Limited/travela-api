<?php

namespace App\Services\Esim;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserEsim;
use App\Support\BundleValidity;

class UserEsimOrderLinkService
{
    /**
     * Link a new assignment to the order bundle purchased at assignment time.
     */
    public function linkAssignmentToOrder(UserEsim $assignment, Order $order): UserEsim
    {
        if ($assignment->order_id && $assignment->bundle_id) {
            return $assignment;
        }

        $item = $this->primaryBundleItem($order);
        $updates = array_filter([
            'order_id' => $assignment->order_id ?? $order->id,
            'bundle_id' => $assignment->bundle_id ?? $item?->bundle_id,
            'order_item_id' => $assignment->order_item_id ?? $item?->id,
        ], fn ($v) => $v !== null);

        if ($updates !== []) {
            $assignment->update($updates);
        }

        return $assignment->fresh(['bundle', 'order', 'orderItem']);
    }

    /**
     * Resolve latest paid order for a user and link the assignment.
     */
    public function linkAssignmentFromLatestPaidOrder(UserEsim $assignment): UserEsim
    {
        $order = $this->paidOrdersForUser((int) $assignment->user_id)->first();

        if (! $order) {
            return $assignment;
        }

        return $this->linkAssignmentToOrder($assignment, $order);
    }

    /**
     * Backfill order/bundle links when an assignment was created before payment completed.
     */
    public function ensureAssignmentLinked(UserEsim $assignment): UserEsim
    {
        if ($assignment->order_id && $assignment->bundle_id) {
            return $assignment;
        }

        return $this->linkAssignmentFromLatestPaidOrder($assignment);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    public function paidOrdersForUser(int $userId)
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere('status', 'paid');
            })
            ->orderByDesc('id');
    }

    /**
     * Bundle from assignment link, or fallback to the user's latest paid order.
     *
     * @return array<string, mixed>|null
     */
    public function bundleForAssignment(UserEsim $assignment): ?array
    {
        $bundle = $assignment->relationLoaded('bundle') ? $assignment->bundle : $assignment->bundle()->first();

        if ($bundle) {
            $orderItem = $assignment->relationLoaded('orderItem')
                ? $assignment->orderItem
                : $assignment->orderItem()->first();
            $order = $assignment->relationLoaded('order') ? $assignment->order : $assignment->order()->first();

            return $this->formatBundlePayload($bundle, $orderItem, $order);
        }

        return $this->bundleForUser((int) $assignment->user_id);
    }

    /**
     * User's latest paid order with purchased bundle (including duration).
     *
     * @return array<string, mixed>|null
     */
    public function latestOrderForUser(int $userId): ?array
    {
        $order = $this->latestPaidOrderWithBundles($userId);
        if (! $order) {
            return null;
        }

        $item = $this->primaryBundleItem($order);
        $bundle = $item
            ? ($item->relationLoaded('bundle') ? $item->bundle : $item->bundle()->first())
            : null;

        return [
            'id' => $order->id,
            'draft_id' => $order->draft_id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'paid_at' => $order->paid_at,
            'created_at' => $order->created_at,
            'bundle' => $this->formatBundlePayload($bundle, $item, $order),
        ];
    }

    /**
     * Primary bundle from the user's latest paid order.
     *
     * @return array<string, mixed>|null
     */
    public function bundleForUser(int $userId): ?array
    {
        return $this->latestOrderForUser($userId)['bundle'] ?? null;
    }

    public function latestPaidOrderWithBundles(int $userId): ?Order
    {
        return $this->paidOrdersForUser($userId)
            ->with([
                'orderItems' => fn ($q) => $q->where('type', 'bundle')->with('bundle.type'),
            ])
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formatBundlePayload(?Bundle $bundle, ?OrderItem $item, ?Order $order = null): ?array
    {
        if (! $bundle && ! $item) {
            return null;
        }

        if ($bundle && ! $bundle->relationLoaded('type')) {
            $bundle->load('type');
        }

        $durationDays = BundleValidity::DAYS;

        return [
            'id' => $bundle?->id ?? $item?->bundle_id,
            'name' => $bundle?->name ?? $item?->bundle_name,
            'alias' => $bundle?->alias,
            'type' => $bundle?->type?->code,
            'type_name' => $bundle?->type?->name,
            'sim_bundle_id' => $bundle?->sim_bundle_id,
            'data_mb' => $bundle?->data_mb ?? $item?->data_amount,
            'bundle_size' => $bundle?->bundle_size,
            'validity_days' => $durationDays,
            'duration_days' => $durationDays,
            'duration' => $durationDays ? sprintf('%d days', $durationDays) : null,
            'price' => $item?->price,
            'price_usd' => $bundle?->price_usd,
            'price_tzs' => $bundle?->price_tzs,
            'currency' => $item?->currency ?? $bundle?->currency ?? $order?->currency,
            'order_id' => $order?->id ?? $item?->order_id,
            'order_draft_id' => $order?->draft_id,
            'order_item_id' => $item?->id,
        ];
    }

    /** The data validity window starts at confirmed recharge, not installation. */
    public function planWindowForAssignment(UserEsim $assignment): array
    {
        $order = Order::query()
            ->where('user_id', $assignment->user_id)
            ->where('recharge_status', 'success')
            ->whereNotNull('recharge_completed_at')
            ->where(function ($query) use ($assignment) {
                $query->where('metadata->recharge->user_esim_id', $assignment->id)
                    ->orWhere('metadata->user_esim_id', $assignment->id);
                if ($assignment->order_id) {
                    $query->orWhere('id', $assignment->order_id);
                }
            })
            ->orderByDesc('recharge_completed_at')
            ->orderByDesc('id')
            ->first();

        $start = $order?->recharge_completed_at;
        $end = $start?->copy()->addDays(BundleValidity::DAYS);

        return [
            'plan_validity_days' => BundleValidity::DAYS,
            'plan_started_at' => $start?->toIso8601String(),
            'plan_expires_at' => $end?->toIso8601String(),
            'plan_expired' => $end ? now()->greaterThanOrEqualTo($end) : false,
        ];
    }

    public function primaryBundleItem(Order $order): ?OrderItem
    {
        if ($order->relationLoaded('orderItems')) {
            return $order->orderItems
                ->where('type', 'bundle')
                ->whereNotNull('bundle_id')
                ->sortBy('id')
                ->first();
        }

        return $order->orderItems()
            ->where('type', 'bundle')
            ->whereNotNull('bundle_id')
            ->orderBy('id')
            ->first();
    }
}
