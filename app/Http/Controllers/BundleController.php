<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Support\BundleVisibility;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function index(Request $request)
    {
        $user = BundleVisibility::resolveOptionalUser($request);

        $q = Bundle::with('countryProvider.provider');

        // Hide bundles not meant for storefront listing.
        // $q->where(function ($w) {
        //     $w->whereNull('alias')->orWhere('alias', '!=', 'Nomad');
        // });
        // $q->where('bundle_size', '!=', 15);

        // if ($request->filled('active')) {
        //     $q->where('active', $request->boolean('active'));
        // }

        $bundles = BundleVisibility::filterBundles(
            $q->orderBy('price_usd')->get(),
            $user,
        );

        $bundles = collect($bundles)->map(function (Bundle $bundle) {
            $data = $bundle->attributesToArray();
            $data['network_id'] = \App\Support\BundleNetwork::resolve($bundle);
            $data['price'] = $data['price_usd'];
            unset($data['price_usd']);

            return $data;
        });

        return response()->json([
            'bundles' => $bundles,
        ])->header('Cache-Control', 'no-store, private');
    }
}

