<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\Esim;

class BundleNetwork
{
    public static function resolve(Bundle $bundle): ?int
    {
        if ($bundle->network_id !== null) {
            return is_numeric($bundle->network_id) && (int) $bundle->network_id > 0 ? (int) $bundle->network_id : null;
        }

        $bundle->loadMissing('countryProvider.provider');
        $provider = $bundle->countryProvider?->provider;
        $configured = data_get($provider?->metadata, 'network_id');
        if (is_numeric($configured) && (int) $configured > 0) return (int) $configured;
        return $provider?->slug === 'vodacom' ? Esim::defaultNetworkId() : null;
    }
}
