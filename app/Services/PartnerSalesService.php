<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PartnerApplication;
use Illuminate\Database\Eloquent\Builder;

class PartnerSalesService
{
    public function rate(PartnerApplication $application): ?int
    {
        $rate = config('partners.commission_bps.'.data_get($application->application_payload, 'type'));
        return is_int($rate) && $rate >= 0 && $rate <= 10000 ? $rate : null;
    }

    public function orders(PartnerApplication $application): Builder
    {
        return Order::whereIn('partner_referral_id', \App\Models\PartnerReferral::where('partner_application_id', $application->id)->select('id'));
    }

    public function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function sale(Order $order): array
    {
        $state = 'pending';
        $payment = strtolower((string) $order->payment_status);
        $status = strtolower((string) $order->status);
        $events = collect(data_get($order->payment_callback, 'deliveries', []))->pluck('event')
            ->push(data_get($order->payment_callback, 'last.event'));
        if (in_array($payment, ['failed', 'refunded', 'reversed', 'cancelled']) || in_array($status, ['cancelled', 'payment_failed', 'refunded', 'reversed'])) {
            $state = 'excluded';
        } elseif ($events->intersect(['payment.on_hold', 'payment.refunded', 'payment.reversed'])->isNotEmpty()) {
            // The gateway currently records these events for manual reconciliation.
            $state = 'review';
        } elseif ($order->partner_commission_bps === null || data_get($order->metadata, 'pricing_version') !== 1 || $order->currency !== 'USD') {
            $state = 'review';
        } elseif ($payment === 'paid' && $order->paid_at !== null) {
            $state = 'confirmed';
        } elseif ($payment === 'paid' || ! in_array($payment, ['pending', 'pending_payment', ''])) {
            $state = 'review';
        }
        $base = $this->cents((string) $order->total_amount);
        $commission = in_array($state, ['confirmed', 'pending'])
            ? intdiv($base * (int) $order->partner_commission_bps + 5000, 10000) : 0;
        return [
            'id' => (string) $order->id,
            'date' => ($state === 'confirmed' ? $order->paid_at : $order->created_at)->toIso8601String(),
            'currency' => $order->currency,
            'amount' => $this->money($base),
            'commission' => $this->money($commission),
            'rateBps' => $order->partner_commission_bps,
            'state' => $state,
        ];
    }

    public function overview(PartnerApplication $application): array
    {
        $today = now('Africa/Dar_es_Salaam')->startOfDay();
        $start = $today->copy()->subDays(29);
        $month = $today->copy()->startOfMonth();
        $daily = [];
        for ($day = $start->copy(); $day <= $today; $day->addDay()) $daily[$day->toDateString()] = 0;
        $totals = [];
        $reviewCount = 0;
        $this->orders($application)->orderBy('id')->chunkById(250, function ($orders) use (&$totals, &$daily, &$reviewCount, $start, $month, $today) {
            foreach ($orders as $order) {
                $sale = $this->sale($order);
                if ($sale['state'] === 'review') $reviewCount++;
                $currency = $sale['currency'];
                $totals[$currency] ??= ['currency' => $currency, 'sales30' => 0, 'revenue30' => 0, 'earnedMonth' => 0, 'earnedAllTime' => 0, 'pending' => 0];
                $commission = $this->cents($sale['commission']);
                if ($sale['state'] === 'pending') $totals[$currency]['pending'] += $commission;
                if ($sale['state'] !== 'confirmed') continue;
                $date = $order->paid_at->copy()->timezone('Africa/Dar_es_Salaam');
                if ($date > $today->copy()->endOfDay()) continue;
                $totals[$currency]['earnedAllTime'] += $commission;
                if ($date >= $month) $totals[$currency]['earnedMonth'] += $commission;
                if ($date >= $start) {
                    $totals[$currency]['sales30']++;
                    $totals[$currency]['revenue30'] += $this->cents($sale['amount']);
                    if ($currency === 'USD') $daily[$date->toDateString()] += $commission;
                }
            }
        });
        $totals['USD'] ??= ['currency' => 'USD', 'sales30' => 0, 'revenue30' => 0, 'earnedMonth' => 0, 'earnedAllTime' => 0, 'pending' => 0];
        foreach ($totals as &$total) foreach (['revenue30', 'earnedMonth', 'earnedAllTime', 'pending'] as $field) $total[$field] = $this->money($total[$field]);
        return [
            'asOf' => now()->toIso8601String(), 'timezone' => 'Africa/Dar_es_Salaam',
            'rateBps' => $this->rate($application), 'totals' => array_values($totals), 'reviewCount' => $reviewCount,
            'daily' => collect($daily)->map(fn ($cents, $date) => ['date' => $date, 'commission' => $this->money($cents)])->values()->all(),
            'latest' => $this->orders($application)->latest('created_at')->latest('id')->limit(6)->get()->map(fn ($order) => $this->sale($order))->all(),
        ];
    }
}
