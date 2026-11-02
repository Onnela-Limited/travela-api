<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Esim\CustomerBundleRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBundleRechargeController extends Controller
{
    public function store(Request $request, User $user, CustomerBundleRechargeService $service): JsonResponse
    {
        abort_unless($user->role === 'user', 422, 'Select a customer account.');
        $data = $request->validate([
            'user_esim_id' => ['required', 'integer'],
            'bundle_id' => ['required', 'integer'],
            'reference' => ['required', 'string', 'max:80', 'regex:/^RECHARGE[a-zA-Z0-9-]+$/'],
        ]);

        return response()->json($service->submit($user, $data, (int) $request->user()->id));
    }
}
