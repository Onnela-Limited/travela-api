<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{PartnerApplication, PartnerBulkOrder, PartnerStockUnit};
use App\Services\PartnerBulkService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Validation\Rule;

class PartnerBulkController extends Controller
{
    private function json(array $data): JsonResponse { return response()->json($data)->header('Cache-Control', 'private, no-store'); }
    private function page(Request $request): void { $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]); }
    private function pagination($page): array { return ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]; }

    public function catalog(Request $request, PartnerBulkService $service): JsonResponse
    {
        return $this->json($service->catalog($request->attributes->get('partner_application')));
    }
    public function index(Request $request, PartnerBulkService $service): JsonResponse
    {
        $application = $request->attributes->get('partner_application'); $service->eligible($application); $this->page($request);
        $page = PartnerBulkOrder::where('partner_application_id', $application->id)->latest('id')->paginate(20);
        return $this->json(['orders' => $page->getCollection()->map(fn ($o) => $service->summary($o))->all(), 'pagination' => $this->pagination($page)]);
    }
    public function store(Request $request, PartnerBulkService $service): JsonResponse
    {
        $application = $request->attributes->get('partner_application'); $service->eligible($application);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'bundle_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:10', 'max:500'],
            'sim_type' => ['required', Rule::in(['esim', 'physical'])], 'expected_total' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/'], 'purchase_confirmed' => ['accepted']]);
        $data['team_actor']=$request->attributes->get('partner_team_member')?->id;
        return $this->json(['order' => $service->summary($service->create($application, $data))]);
    }
    public function stock(Request $request, PartnerBulkService $service): JsonResponse
    {
        $application = $request->attributes->get('partner_application'); $service->eligible($application); $this->page($request);
        $filter = $request->validate(['filter' => ['nullable', Rule::in(['unassigned', 'assigned'])]])['filter'] ?? null;
        $query = $service->units($application);
        if ($filter) $filter === 'unassigned' ? $query->whereNull('user_id') : $query->whereNotNull('user_id');
        $page = $query->with(['bulkOrder', 'esim', 'assignment', 'order'])->latest('id')->paginate(20);
        return $this->json(['units' => $page->getCollection()->map(fn ($unit) => $service->stock($unit))->all(), 'pagination' => $this->pagination($page),
            'counts' => ['available' => $service->units($application)->whereNull('user_id')->count(), 'assigned' => $service->units($application)->whereNotNull('user_id')->count()]]);
    }
    public function assign(Request $request, int $unit, PartnerBulkService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:254'],
            'arrival' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'], 'guest_confirmed' => ['accepted']]);
        $data['team_actor']=$request->attributes->get('partner_team_member')?->id;
        return $this->json(['unit' => $service->stock($service->assign($request->attributes->get('partner_application'), $unit, $data))]);
    }
    public function handover(Request $request, int $unit, PartnerBulkService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'handover_confirmed' => ['accepted']]);
        return $this->json(['unit' => $service->stock($service->handover($request->attributes->get('partner_application'), $unit, (int) $data['expected_version'], $request->attributes->get('partner_team_member')?->id))]);
    }
    public function adminIndex(Request $request, PartnerBulkService $service): JsonResponse
    {
        $this->page($request);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['pending_payment', 'paid', 'approved', 'rejected', 'fulfilled', 'cancelled'])]])['status'] ?? null;
        $page = PartnerBulkOrder::when($filter, fn ($q) => $q->where('status', $filter))->latest('id')->paginate(20);
        return $this->json(['orders' => $page->getCollection()->map(fn ($o) => $service->summary($o))->all(), 'pagination' => $this->pagination($page)]);
    }
    public function show(PartnerBulkOrder $bulk, PartnerBulkService $service): JsonResponse
    {
        $application = PartnerApplication::findOrFail($bulk->partner_application_id);
        $events = DB::table('partner_bulk_events')->where('partner_bulk_order_id', $bulk->id)->orderBy('id')->get()->map(fn ($e) => [
            'id' => (string) $e->id, 'actor' => $e->actor_type.' #'.$e->actor_id, 'action' => $e->action,
            'message' => $e->message === null ? null : Crypt::decryptString($e->message), 'createdAt' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String(),
        ])->all();
        return $this->json(['order' => [...$service->summary($bulk), 'applicationId' => $application->id, 'partner' => data_get($application->application_payload, 'profile.displayName', ''),
            'readyInventory' => $service->inventory($bulk)->count(), 'allocated' => PartnerStockUnit::where('partner_bulk_order_id', $bulk->id)->count(), 'history' => $events]]);
    }
    public function checkout(Request $request, int $bulk): JsonResponse
    {
        try { return $this->json(app(\App\Services\PartnerBulkPaymentService::class)->checkout($request->attributes->get('partner_application'),$bulk)); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException) { return $this->json(['message'=>'Checkout is being prepared. Refresh before retrying.'])->setStatusCode(409); }
        catch (\RuntimeException|\Illuminate\Http\Client\ConnectionException $failure) {
            if ($failure instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $failure instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) throw $failure;
            return $this->json(['message'=>'Online checkout is temporarily unavailable. Your order is saved; retry payment later.'])->setStatusCode(503);
        }
    }
    public function refreshPayment(Request $request, int $bulk, PartnerBulkService $service): JsonResponse
    {
        $order = app(\App\Services\PartnerBulkPaymentService::class)->refresh($request->attributes->get('partner_application'),$bulk);
        return $this->json(['order'=>$service->summary($order)]);
    }
    public function review(Request $request, PartnerBulkOrder $bulk, PartnerBulkService $service): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected', 'fulfilled', 'cancelled'])], 'expected_version' => ['required', 'integer', 'min:0'],
            'no_payment_confirmed' => ['accepted_if:decision,cancelled'], 'rejection_acknowledged' => ['accepted_if:decision,rejected'], 'message' => ['required_if:decision,cancelled,rejected','nullable','string','max:2000']]);
        return $this->json(['order' => $service->summary($service->review($bulk->id, $request->user(), $data))]);
    }
}
