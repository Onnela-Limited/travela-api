<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Esim, User, UserEsim};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerNumberAssignmentController extends Controller
{
    public function available(Request $request, User $user)
    {
        abort_unless($user->role === 'user', 422, 'Select a customer account.');
        $data = $request->validate(['user_esim_id' => ['required', 'integer'], 'search' => ['nullable', 'string', 'max:40']]);
        $previous = UserEsim::with('esim')->where('user_id', $user->id)->findOrFail($data['user_esim_id']);
        $this->requireEsim($previous);
        $query = $this->availableQuery($previous);
        if ($search = trim($data['search'] ?? '')) $query->where('msisdn', 'like', '%'.$search.'%');
        return response()->json($query->orderBy('msisdn')->paginate(20, ['id', 'msisdn', 'network_id']));
    }

    public function store(Request $request, User $user)
    {
        abort_unless($user->role === 'user', 422, 'Select a customer account.');
        $data = $request->validate(['previous_user_esim_id' => ['required', 'integer'],
            'esim_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid']]);
        $assignment = DB::transaction(function () use ($user, $data, $request) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = UserEsim::where('number_assignment_request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id && (int) $existing->esim_id === (int) $data['esim_id']
                    && (int) $existing->previous_user_esim_id === (int) $data['previous_user_esim_id'], 409, 'This request was used for a different assignment.');
                return $existing;
            }
            $previous = UserEsim::with('esim')->where('user_id', $user->id)->lockForUpdate()->findOrFail($data['previous_user_esim_id']);
            $this->requireEsim($previous);
            $candidate = $this->availableQuery($previous)->whereKey($data['esim_id'])->lockForUpdate()->first();
            abort_unless($candidate, 409, 'This number is no longer available. Refresh the available numbers.');
            $assignment = UserEsim::create(['user_id' => $user->id, 'esim_id' => $candidate->id,
                'number_assignment_request_id' => $data['request_id'], 'number_assigned_by' => $request->user()->id,
                'previous_user_esim_id' => $previous->id]);
            $candidate->update(['status' => 'MANAGED', 'sale_status' => Esim::SALE_STATUS_SOLD]);
            return $assignment;
        });
        return response()->json(['success' => true, 'user_esim_id' => $assignment->id,
            'new_number' => $assignment->esim->msisdn,
            'message' => 'New number assigned. The original number remains reserved. No bundle recharge or email was sent.']);
    }

    private function requireEsim(UserEsim $assignment): void
    {
        abort_unless($assignment->esim?->sim_type === Esim::SIM_TYPE_ESIM, 422, 'Select an existing customer eSIM.');
    }

    private function availableQuery(UserEsim $previous)
    {
        $query = Esim::availableForAssignment()->where('sim_type', Esim::SIM_TYPE_ESIM)
            ->where('provider_status', Esim::PROVIDER_STATUS_ACTIVE)->where('network_id', '>', 0)
            ->whereNotNull('msisdn')->whereRaw("TRIM(msisdn) <> ''")
            ->whereNotNull('qr_code_data')->whereRaw("TRIM(qr_code_data) <> ''");
        if ($previous->esim->network_id > 0) $query->where('network_id', $previous->esim->network_id);
        return $query;
    }
}
