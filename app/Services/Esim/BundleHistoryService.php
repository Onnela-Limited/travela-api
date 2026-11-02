<?php

namespace App\Services\Esim;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\UserEsim;
use App\Support\BundleValidity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BundleHistoryService
{
    public static function dataMb(?Bundle $bundle): ?int
    {
        if (! $bundle) return null;
        if ($bundle->data_mb > 0) return (int) $bundle->data_mb;
        if ($bundle->bundle_size_in_mb > 0) return (int) $bundle->bundle_size_in_mb;
        if ($bundle->bundle_size > 0) return (int) round((float) $bundle->bundle_size *
            (strtoupper((string) $bundle->unit) === 'GB' ? 1024 : 1));
        return null;
    }

    public function forAssignment(UserEsim $assignment): array
    {
        $orders = Order::query()->where('user_id', $assignment->user_id)
            ->whereNull('metadata->partner_bulk_invoice_id')
            ->where(function ($query) use ($assignment) {
                $query->where('metadata->recharge->user_esim_id', $assignment->id)
                    ->orWhere('metadata->user_esim_id', $assignment->id);
                if ($assignment->order_id) $query->orWhere('id', $assignment->order_id);
            })
            ->where(function ($query) {
                $query->where('recharge_status', 'success')->orWhereHas('orderItems', fn ($items) =>
                    $items->where('metadata->recharge->status', 'success'));
            })->with('orderItems.bundle')->get();

        $history = [];
        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                if ($item->type !== 'bundle') continue;
                $itemStatus = data_get($item->metadata, 'recharge.status');
                if ($itemStatus !== 'success' && ! ($itemStatus === null && $order->recharge_status === 'success')) continue;
                $started = $order->recharge_completed_at ?? data_get($item->metadata, 'recharge.requested_at');
                if (! $started) continue;
                $history[] = $this->entry('order-item-'.$item->id, $item->bundle_name ?: $item->bundle?->name,
                    $item->data_amount > 0 ? (int) $item->data_amount : self::dataMb($item->bundle),
                    $started, data_get($item->metadata, 'recharge.reference') ?? $order->recharge_reference,
                    data_get($order->metadata, 'checkoutMode') === 'topup' ? 'topup' : 'purchase');
            }
        }

        $adminRecharges = DB::table('customer_bundle_recharges')
            ->where('user_id', $assignment->user_id)->where('user_esim_id', $assignment->id)
            ->where('status', 'success')->get();
        $bundles = Bundle::whereIn('id', $adminRecharges->pluck('bundle_id'))->get()->keyBy('id');
        foreach ($adminRecharges as $row) {
            $history[] = $this->entry('admin-recharge-'.$row->id, $row->bundle_name,
                $row->data_mb ?? self::dataMb($bundles->get($row->bundle_id)),
                $row->completed_at ?? $row->created_at, $row->reference, 'admin_topup');
        }
        usort($history, fn ($a, $b) => strcmp($b['added_at'], $a['added_at']) ?: strcmp($a['id'], $b['id']));
        $active = array_values(array_filter($history, fn ($item) => ! $item['expired']));
        $activeData = array_sum(array_column($active, 'data_mb'));
        return [
            'bundle_history' => $history,
            // Purchased allowance is separate from the network's remaining DATA balance.
            'active_bundles_data_mb' => $activeData,
        ];
    }

    private function entry(string $id, ?string $name, ?int $dataMb, $started, ?string $reference, string $source): array
    {
        $start = Carbon::parse($started);
        $end = $start->copy()->addDays(BundleValidity::DAYS);
        return [
            'id' => $id, 'name' => $name ?: 'Data bundle', 'data_mb' => $dataMb,
            'added_at' => $start->toIso8601String(), 'expires_at' => $end->toIso8601String(),
            'expired' => now()->greaterThanOrEqualTo($end), 'reference' => $reference, 'source' => $source,
        ];
    }
}
