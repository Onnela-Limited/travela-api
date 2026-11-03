<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\Esim;

class BundleNetwork
{
    // BundleSeeder historically attached these verified Vodacom products to the
    // default catalog provider (often TTCL), leaving their network_id unset.
    private const LEGACY_SEEDED_PRODUCT_IDS = [66, 3232, 3233, 3234, 28];
    public static function resolve(Bundle $bundle): ?int
    {
        if ($bundle->network_id !== null) {
            return is_numeric($bundle->network_id) && (int) $bundle->network_id > 0 ? (int) $bundle->network_id : null;
        }

        $bundle->loadMissing('countryProvider.provider');
        $provider = $bundle->countryProvider?->provider;
        $configured = data_get($provider?->metadata, 'network_id');
        if (is_numeric($configured) && (int) $configured > 0) return (int) $configured;
        $legacySeededProduct = data_get($bundle->metadata, 'source') === 'BundleSeeder'
            && in_array((int) $bundle->sim_bundle_id, self::LEGACY_SEEDED_PRODUCT_IDS, true);
        if ($provider?->slug === 'vodacom' || $legacySeededProduct) {
            $network = Esim::defaultNetworkId();
            return $network > 0 ? $network : null;
        }
        return null;
    }
}
