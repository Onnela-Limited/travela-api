<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\CustomerActivationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerActivationRetryController extends Controller
{
    public function store(Request $request, User $user, UserEsim $userEsim, CustomerActivationRetryService $service): JsonResponse
    {
        abort_unless($user->role === 'user', 422, 'Select a customer account.');
        abort_unless((int) $userEsim->user_id === (int) $user->id, 404);
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        return response()->json($service->allow($user, $userEsim->id, (int) $request->user()->id, $data['request_id']));
    }
}
