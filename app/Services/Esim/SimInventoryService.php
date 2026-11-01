<?php

namespace App\Services\Esim;

use App\Models\Esim;
use Illuminate\Support\Facades\DB;

class SimInventoryService
{
    /**
     * Stock levels from local esims + user_esims assignment table.
     *
     * @return array{
     *     network_id: int,
     *     sim_type: string,
     *     threshold: array{low: int, critical: int},
     *     stock: array<string, int|float>,
     *     alerts: array<string, bool>,
     *     message: ?string
     * }
     */
    public function report(?int $networkId = null, string $simType = 'all'): array
    {
        $networkId = $networkId ?? (int) config('travela.inventory.default_network_id', 1);
        $lowThreshold = (int) config('travela.inventory.low_stock_threshold', 5);
        $criticalThreshold = (int) config('travela.inventory.critical_stock_threshold', 2);

        $stock = $this->stockLevels($networkId, $simType);
        $available = (int) $stock['available'];
        $alerts = $this->evaluateAlerts($available, $lowThreshold, $criticalThreshold);

        return [
            'network_id' => $networkId,
            'sim_type' => $simType,
            'threshold' => [
                'low' => $lowThreshold,
                'critical' => $criticalThreshold,
            ],
            'stock' => $stock,
            'alerts' => $alerts,
            'message' => $this->alertMessage($available, $alerts, $lowThreshold, $criticalThreshold),
        ];
    }

    /**
     * @return array{
     *     total_pool: int,
     *     available: int,
     *     assigned: int,
     *     suspended: int,
     *     physical_total: int,
     *     physical_available: int,
     *     physical_assigned: int,
     *     esim_total: int,
     *     esim_available: int,
     *     esim_assigned: int,
     *     utilization_percent: float
     * }
     */
    public function stockLevels(int $networkId, string $simType = 'all'): array
    {
        $simType = strtolower($simType);

        $base = DB::table('esims')->where('network_id', $networkId);
        if ($simType === Esim::SIM_TYPE_PHYSICAL || $simType === Esim::SIM_TYPE_ESIM) {
            $base->where('sim_type', $simType);
        }

        $totalPool = (int) (clone $base)->count();

        $available = (int) (clone $base)
            ->leftJoin('user_esims', 'user_esims.esim_id', '=', 'esims.id')
            ->where('esims.provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->whereNull('user_esims.id')
            ->whereNull('esims.partner_bulk_order_id')
            ->count('esims.id');

        $assigned = (int) (clone $base)
            ->join('user_esims', 'user_esims.esim_id', '=', 'esims.id')
            ->where('esims.provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->count('esims.id');

        $suspended = (int) (clone $base)
            ->where('provider_status', Esim::PROVIDER_STATUS_SUSPENDED)
            ->count();

        $physical = $this->typeBreakdown($networkId, Esim::SIM_TYPE_PHYSICAL);
        $esim = $this->typeBreakdown($networkId, Esim::SIM_TYPE_ESIM);

        $assignable = max($available + $assigned, 1);

        return [
            'total_pool' => $totalPool,
            'available' => $available,
            'assigned' => $assigned,
            'suspended' => $suspended,
            'physical_total' => $physical['total'],
            'physical_available' => $physical['available'],
            'physical_assigned' => $physical['assigned'],
            'esim_total' => $esim['total'],
            'esim_available' => $esim['available'],
            'esim_assigned' => $esim['assigned'],
            'utilization_percent' => round(($assigned / $assignable) * 100, 2),
        ];
    }

    /**
     * @return array{total: int, available: int, assigned: int}
     */
    private function typeBreakdown(int $networkId, string $simType): array
    {
        $base = DB::table('esims')
            ->where('network_id', $networkId)
            ->where('sim_type', $simType);

        $total = (int) (clone $base)->count();

        $available = (int) (clone $base)
            ->leftJoin('user_esims', 'user_esims.esim_id', '=', 'esims.id')
            ->where('esims.provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->whereNull('user_esims.id')
            ->whereNull('esims.partner_bulk_order_id')
            ->count('esims.id');

        $assigned = (int) (clone $base)
            ->join('user_esims', 'user_esims.esim_id', '=', 'esims.id')
            ->where('esims.provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->count('esims.id');

        return [
            'total' => $total,
            'available' => $available,
            'assigned' => $assigned,
        ];
    }

    /**
     * @return array{out_of_stock: bool, low_stock: bool, critical_stock: bool}
     */
    public function evaluateAlerts(int $available, int $lowThreshold, int $criticalThreshold): array
    {
        return [
            'out_of_stock' => $available === 0,
            'low_stock' => $available > 0 && $available <= $lowThreshold,
            'critical_stock' => $available > 0 && $available <= $criticalThreshold,
        ];
    }

    /**
     * @param  array{out_of_stock: bool, low_stock: bool, critical_stock: bool}  $alerts
     */
    private function alertMessage(
        int $available,
        array $alerts,
        int $lowThreshold,
        int $criticalThreshold,
    ): ?string {
        if ($alerts['out_of_stock']) {
            return 'Inventory is out of stock. Add numbers immediately.';
        }

        if ($alerts['critical_stock']) {
            return sprintf(
                'Critical: only %d number(s) available (critical threshold: %d).',
                $available,
                $criticalThreshold
            );
        }

        if ($alerts['low_stock']) {
            return sprintf(
                'Inventory is running low. Only %d number(s) available (threshold: %d).',
                $available,
                $lowThreshold
            );
        }

        return null;
    }
}
