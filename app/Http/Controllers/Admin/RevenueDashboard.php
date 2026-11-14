<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevenueDashboard extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->stats($request);
    }

    public function stats(Request $request): JsonResponse
    {
        $input = $request->validate(['period' => ['sometimes', Rule::in(['this_month', 'all_time'])]]);
        // Keep the historical default for existing API consumers; Revenue explicitly requests this month.
        $period = $input['period'] ?? 'all_time';
        $start = $period === 'this_month' ? now()->startOfMonth() : null;
        $end = $start?->copy()->addMonth();
        // Match this report's existing basis: order creation date, not payment settlement date.
        $inPeriod = static function ($query) use ($start, $end) {
            if ($start) $query->where('orders.created_at', '>=', $start)->where('orders.created_at', '<', $end);
            return $query;
        };
        $ordersQuery = $inPeriod(Order::query()->where('status', '!=', 'draft'));
        $paidQuery = $inPeriod(Order::query()->where('status', 'paid'));

        $totalOrders = (int) $ordersQuery->count();
        $totalRevenue = (float) ($paidQuery->sum('total_amount') ?? 0);
        $averageOrderValue = (float) ($paidQuery->avg('total_amount') ?? 0);

        $paidBundleLines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '=', 'paid')
            ->where('order_items.type', '=', 'bundle')
            ->whereNotNull('order_items.bundle_id');
        $inPeriod($paidBundleLines);

        /** @var Collection<string, object> */
        $totalsByCurrency = (clone $paidBundleLines)
            ->groupBy('order_items.currency')
            ->select(
                'order_items.currency',
                DB::raw('SUM(order_items.price) as total_line_revenue'),
            )
            ->get()
            ->keyBy('currency');

        /** @var Collection<int, object> */
        $revenueRows = (clone $paidBundleLines)
            ->leftJoin('bundles', 'bundles.id', '=', 'order_items.bundle_id')
            ->groupBy(
                'order_items.bundle_id',
                'bundles.name',
                'bundles.alias',
                'order_items.bundle_name',
                'order_items.currency',
            )
            ->orderByDesc(DB::raw('SUM(order_items.price)'))
            ->get([
                'order_items.bundle_id as bundle_id',
                'bundles.alias as bundle_alias',
                DB::raw('COALESCE(bundles.name, order_items.bundle_name) as bundle_name'),
                'order_items.currency as currency',
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.price) as revenue'),
            ]);

        $revenueByBundle = $revenueRows->map(function ($row) use ($totalsByCurrency) {
            $tot = $totalsByCurrency->get($row->currency);
            $totalLineRevenue = $tot ? (float) $tot->total_line_revenue : 0.0;
            $ordersCount = (int) $row->orders_count;
            $revenue = (float) $row->revenue;

            return [
                'bundle_id' => (int) $row->bundle_id,
                'bundle_alias' => $row->bundle_alias,
                'bundle_name' => $row->bundle_name,
                'currency' => $row->currency,
                'orders' => $ordersCount,
                'revenue' => $revenue,
                'revenue_share_percent' => $totalLineRevenue > 0
                    ? round(100 * $revenue / $totalLineRevenue, 2)
                    : 0.0,
            ];
        })->values();

        $soldBundleIds = $revenueByBundle->pluck('bundle_id')->unique()->all();
        $canonicalAliases = ['Starter', 'Explorer', 'Traveller', 'Nomad'];

        foreach ($canonicalAliases as $alias) {
            $b = DB::table('bundles')->where('alias', $alias)->first();
            if (! $b || in_array((int) $b->id, $soldBundleIds, true)) {
                continue;
            }

            $revenueByBundle->push([
                'bundle_id' => (int) $b->id,
                'bundle_alias' => $b->alias,
                'bundle_name' => $b->name,
                'currency' => $b->currency,
                'orders' => 0,
                'revenue' => 0.0,
                'revenue_share_percent' => 0.0,
            ]);
        }

        $revenueByBundle = $revenueByBundle->sortByDesc('revenue')->values();

        $revenueByCountry = $inPeriod(DB::table('orders'))
            ->join('trips', 'trips.order_id', '=', 'orders.id')
            ->where('orders.status', '=', 'paid')
            ->groupBy('trips.destination_country', 'orders.currency')
            ->orderByDesc(DB::raw('SUM(orders.total_amount)'))
            ->get([
                'trips.destination_country as country',
                'orders.currency as currency',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(orders.total_amount) as revenue'),
            ])
            ->map(function ($row) {
                return [
                    'country' => $row->country,
                    'currency' => $row->currency,
                    'orders' => (int) $row->orders,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $monthOverMonthRevenue = DB::table('orders')
            ->where('status', '=', 'paid')
            // Use a month key to satisfy MySQL ONLY_FULL_GROUP_BY.
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"), 'currency')
            ->orderByDesc(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->limit(2)
            ->get([
                DB::raw("DATE_FORMAT(MIN(created_at), '%b %Y') as month"),
                'currency',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(total_amount) as revenue'),
            ])
            // UI shows oldest → newest (e.g. Apr then May)
            ->reverse()
            ->values()
            ->map(function ($row) {
                return [
                    'month' => $row->month,
                    'currency' => $row->currency,
                    'orders' => (int) $row->orders,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $ordersAllTime = $inPeriod(DB::table('orders'))
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->join('trips', 'trips.order_id', '=', 'orders.id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('bundles', 'bundles.id', '=', 'order_items.bundle_id')
            ->where('orders.status', '=', 'paid')
            ->where('order_items.type', '=', 'bundle')
            ->groupBy(
                'orders.id',
                'users.name',
                'orders.created_at',
                'orders.currency',
                'trips.destination_country',
                'order_items.bundle_id',
                'bundles.name',
                'order_items.bundle_name',
            )
            ->orderByDesc('orders.created_at')
            ->limit(10)
            ->get([
                'orders.id as order_id',
                'users.name as customer',
                DB::raw('COALESCE(bundles.name, order_items.bundle_name) as bundle'),
                'trips.destination_country as country',
                'orders.created_at as date',
                'orders.currency as currency',
                DB::raw('COUNT(order_items.id) as qty'),
                DB::raw('SUM(order_items.price) as revenue'),
            ])
            ->map(function ($row) {
                return [
                    'order_id' => (int) $row->order_id,
                    'customer' => $row->customer,
                    'bundle' => $row->bundle,
                    'country' => $row->country,
                    'date' => $row->date,
                    'currency' => $row->currency,
                    'qty' => (int) $row->qty,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'reporting_period' => [
                    'key' => $period,
                    'label' => $start ? $start->format('F Y') : 'All time',
                    'timezone' => config('app.timezone'),
                    'starts_at' => $start?->toIso8601String(),
                    'ends_before' => $end?->toIso8601String(),
                ],
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'average_order_value' => $averageOrderValue,
                'revenue_by_bundle' => $revenueByBundle,
                'revenue_by_country' => $revenueByCountry,
                'month_over_month_revenue' => $monthOverMonthRevenue,
                'orders_all_time' => $ordersAllTime,
            ],
        ]);
    }
}

