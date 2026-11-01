<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerPayout;
use App\Services\PartnerPayoutService;
use App\Services\PartnerSalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPayoutController extends Controller
{
    public function index(Request $request, PartnerPayoutService $service, PartnerSalesService $sales): JsonResponse
    {
        $application = $request->attributes->get('partner_application');
        abort_unless($application->status === 'approved', 403);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $destination = null;
        try { $destination = $service->masked($service->destination($application)); }
        catch (ValidationException) { /* An incomplete saved destination must not prevent reading history. */ }
        $balances = array_map(fn ($cents) => $sales->money($cents), $service->balances($application));
        $page = PartnerPayout::where('partner_application_id', $application->id)->latest('id')->paginate(20);
        return response()->json(['currency' => 'USD', 'minimum' => $sales->money((int) config('partners.minimum_payout_cents', 1000)),
            'balances' => $balances, 'destination' => $destination, 'settingsVersion'=>(int)($application->settings_version ?? 0),
            'activeRequest' => PartnerPayout::where('partner_application_id', $application->id)->whereIn('status', ['pending', 'processing'])->exists(),
            'payouts' => $page->getCollection()->map(fn ($payout) => $service->summary($payout))->all(),
            'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, PartnerPayoutService $service): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'expected_amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/'], 'expected_settings_version'=>['sometimes','integer','min:0'], 'destination_confirmed' => ['accepted']]);
        $payout = $service->request($request->attributes->get('partner_application'), strtolower($data['request_key']), $data['expected_amount'], (int)($data['expected_settings_version'] ?? 0));
        return response()->json(['payout' => $service->summary($payout)])->header('Cache-Control', 'private, no-store');
    }

    public function adminIndex(Request $request, PartnerPayoutService $service): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'processing', 'paid', 'rejected'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $query = PartnerPayout::query();
        if (! empty($data['status'])) $query->where('status', $data['status']);
        $page = $query->latest('id')->paginate(20);
        $references = PartnerApplication::whereIn('id', $page->getCollection()->pluck('partner_application_id'))->pluck('reference', 'id');
        return response()->json(['payouts' => $page->getCollection()->map(fn ($payout) => [...$service->summary($payout), 'applicationReference' => $references[$payout->partner_application_id] ?? ''])->all(),
            'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(PartnerPayout $payout, PartnerPayoutService $service, PartnerSalesService $sales): JsonResponse
    {
        $application = PartnerApplication::findOrFail($payout->partner_application_id);
        $history = DB::table('partner_payout_events')->where('partner_payout_id', $payout->id)->orderBy('id')->get()->map(fn ($event) => [
            'id' => (string) $event->id, 'actor' => $event->actor_name, 'fromStatus' => $event->from_status, 'toStatus' => $event->to_status,
            'message' => $event->message === null ? null : Crypt::decryptString($event->message), 'createdAt' => \Illuminate\Support\Carbon::parse($event->created_at)->toIso8601String(),
        ])->all();
        return response()->json(['payout' => [...$service->summary($payout), 'applicationReference' => $application->reference,
            'applicationId' => $application->id, 'partnerName' => data_get($application->application_payload, 'profile.displayName', ''),
            'partnerStatus' => $application->status, 'fullDestination' => $payout->destination,
            'processingAdminId' => $payout->processing_admin_id, 'processingAdminName' => $payout->processing_admin_name,
            'balances' => array_map(fn ($cents) => $sales->money($cents), $service->balances($application)), 'history' => $history,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, PartnerPayout $payout, PartnerPayoutService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['processing', 'paid', 'rejected'])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'message' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
            'transfer_confirmed' => ['accepted_if:decision,paid'],
            'no_transfer_confirmed' => ['accepted_if:decision,rejected'],
            'payment_reference' => ['required_if:decision,paid', 'nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{2,159}$/'],
            'transfer_currency' => ['required_if:decision,paid', 'nullable', Rule::in(['USD', 'TZS'])],
            'transfer_amount' => ['required_if:decision,paid', 'nullable', 'string', 'regex:/^\d{1,10}\.\d{2}$/', function ($attribute, $value, $fail) {
                if ($value !== null && (float) $value <= 0) $fail('Enter a positive transfer amount.');
            }],
        ]);
        $service->decide($payout->id, $request->user(), $data);
        return response()->json(['message' => 'Payout updated.'])->header('Cache-Control', 'private, no-store');
    }
}
