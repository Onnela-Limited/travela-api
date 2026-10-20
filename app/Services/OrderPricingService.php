<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\User;
use App\Support\BundleValidity;
use App\Support\BundleVisibility;
use Illuminate\Validation\ValidationException;

class OrderPricingService
{
    /** Resolve all commercial and fulfillment fields from the server catalog. */
    public function price(array $checkout, ?User $user): array
    {
        $items = [];
        $subtotalCents = 0;

        foreach ($checkout['items'] as $index => $item) {
            $bundle = ($item['type'] ?? null) === 'bundle'
                ? Bundle::find($item['bundle_id'] ?? null) : null;

            if (! $bundle || ! $bundle->active || ! BundleVisibility::visibleTo($user, $bundle)) {
                throw ValidationException::withMessages([
                    "items.$index.bundle_id" => 'Select an available catalog bundle.',
                ]);
            }

            if ($bundle->price_usd === null || (float) $bundle->price_usd <= 0) {
                throw ValidationException::withMessages([
                    "items.$index.bundle_id" => 'This bundle has no valid USD checkout price.',
                ]);
            }

            $priceCents = (int) round((float) $bundle->price_usd * 100);
            $subtotalCents += $priceCents;
            $dataMb = $bundle->data_mb ?? $bundle->bundle_size_in_mb;
            if ($dataMb === null && $bundle->bundle_size !== null) {
                $dataMb = (int) round((float) $bundle->bundle_size *
                    (strtoupper((string) $bundle->unit) === 'GB' ? 1024 : 1));
            }
            $items[] = [
                'type' => 'bundle',
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'data_amount' => $dataMb,
                'validity_days' => BundleValidity::DAYS,
                'price' => $priceCents / 100,
                'currency' => 'USD',
            ];
        }

        // No server-side promotion system exists; browser discounts are never authoritative.
        $checkout['items'] = $items;
        $checkout['pricing'] = [
            'subtotal' => $subtotalCents / 100,
            'discount_amount' => 0,
            'discount_code' => null,
            'total_amount' => $subtotalCents / 100,
            'currency' => 'USD',
        ];

        return $checkout;
    }
}
