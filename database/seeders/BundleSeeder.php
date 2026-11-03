<?php

namespace Database\Seeders;

use App\Models\Bundle;
use App\Models\BundleType;
use App\Models\CountryProvider;
use App\Models\Esim;
use Illuminate\Database\Seeder;

class BundleSeeder extends Seeder
{
    /**
     * Travela storefront tiers (30‑day USD) + optional larger SIM SKUs.
     *
     * Verified Vodacom/sim product IDs:
     * - 3232 → 10 GB, 3233 → 15 GB, 3234 → 30 GB, 28 → 50 GB
     *
     * Also used (legacy catalog / placeholders — confirm via SIM product API):
     * - Starter 1 GB → 25 (nearest 30d plan in legacy seed data)
     * - Explorer 3 GB → 3231, Traveller 5 GB → 3230 (DataPre 5 GB)
     */
    public function run(): void
    {
        $dataType = BundleType::where('code', 'DATA')->first();

        if (! $dataType) {
            $dataType = BundleType::create([
                'code' => 'DATA',
                'name' => 'Data only',
            ]);
        }

        $pivot = CountryProvider::where('is_default', true)->first()
            ?? CountryProvider::query()->first();

        if (! $pivot) {
            $this->command->warn('No CountryProvider row found. Seed CountryProviderSeeder before BundleSeeder.');

            return;
        }

        $currency = 'USD';
        /** Larger data SKUs tied to verified sim_bundle_id values (inactive until you publish pricing). */
        $simOnlyBundles = [
            [
                'alias' => 'Starter',
                'name' => 'Internet - 30Days - 25MB',
                'bundle_size' => 25,
                'bundle_size_in_mb' => 25,
                'data_mb' => 25,
                'validity_days' => 30,
                'price_usd' => 0,
                'sim_bundle_id' => 66,
                'unit' => 'MB',
                'active' => true,
                'metadata' => [
                    'source' => 'BundleSeeder',
                    'admin_only' => true,
                    'local_only' => true,
                ],
            ],
            [
                'alias' => 'Nomad',
                'name' => 'Nomad 10GB',
                'bundle_size' => 10,
                'validity_days' => 30,
                'price_usd' => 90.00,
                'sim_bundle_id' => 3232,
                'unit' => 'GB',
                'active' => true,
            ],
            [
                'name' => '15GB (30 days)',
                'bundle_size' => 15,
                'validity_days' => 30,
                'price_usd' => 120.00,
                'sim_bundle_id' => 3233,
                'unit' => 'GB',
                'active' => false,
            ],
            [
                'name' => '30GB (30 days)',
                'bundle_size' => 30,
                'validity_days' => 30,
                'price_usd' => 200.00,
                'sim_bundle_id' => 3234,
                'unit' => 'GB',
                'active' => false,
            ],
            [
                'name' => '50GB (30 days)',
                'bundle_size' => 50,
                'validity_days' => 30,
                'price_usd' => 320.00,
                'sim_bundle_id' => 28,
                'unit' => 'GB',
                'active' => false,
            ],
        ];

        $keepSimIds = array_map(fn (array $r) => $r['sim_bundle_id'], $simOnlyBundles);

        // Ensure only these SIM bundles remain for this CountryProvider.
        Bundle::query()
            ->where('country_provider_id', $pivot->id)
            ->where(function ($q) use ($keepSimIds) {
                $q->whereNull('sim_bundle_id')
                    ->orWhereNotIn('sim_bundle_id', $keepSimIds);
            })
            ->delete();


        foreach ($simOnlyBundles as $row) {
            $payload = array_merge($this->basePayload($dataType->id, $pivot->id, $currency), $row);
            Bundle::updateOrCreate(
                [
                    'country_provider_id' => $pivot->id,
                    'sim_bundle_id' => $row['sim_bundle_id'],
                ],
                $payload
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $bundleTypeId, int $countryProviderId, string $currency): array
    {
        return [
            'bundle_type_id' => $bundleTypeId,
            'country_provider_id' => $countryProviderId,
            // Every product in this seeder belongs to the configured SIM network.
            'network_id' => Esim::defaultNetworkId(),
            'external_id' => null,
            'voice_minutes' => null,
            'sms' => null,
            'product_code' => null,
            'currency' => $currency,
            'metadata' => ['source' => 'BundleSeeder'],
        ];
    }
}
