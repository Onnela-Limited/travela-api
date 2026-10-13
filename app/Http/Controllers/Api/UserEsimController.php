<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeEsimRechargeRequest;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\Esim\OrderRechargeService;
use App\Services\Esim\SimAssignmentService;
use App\Services\Esim\UserEsimOrderLinkService;
use App\Services\Esim\VodacomBalanceService;
use App\Services\Esim\VodacomRechargePayload;
use App\Services\Esim\VodacomSimManagerService;
use App\Services\EvPay\EvPayCheckoutService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserEsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly OrderRechargeService $orderRecharge,
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly SimAssignmentService $simAssignment,
        private readonly VodacomBalanceService $balances,
        private readonly EvPayCheckoutService $evpay,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        $esims = $request->user()
            ->esims()
            ->with(['esim', 'bundle.type', 'order', 'orderItem'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (UserEsim $row) {
                $row = $this->esimOrderLink->ensureAssignmentLinked($row);
                $row->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

                return $row->toAssignmentArray();
            });

        return response()->json([
            'success' => true,
            'data' => $esims,
            'latest_order' => $this->esimOrderLink->latestOrderForUser($userId),
            'assignment_prompt' => $this->simAssignment->assignmentPromptForUser($userId),
        ]);
    }

    /**
     * Activation payload (`qr_code_data`) for the user's assigned eSIM (from import).
     */
    public function activation(Request $request, UserEsim $userEsim): JsonResponse
    {
        if ((int) $userEsim->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this eSIM.',
            ], 403);
        }

        $userEsim->loadMissing('esim');
        $esim = $userEsim->esim;

        if (! $esim || $esim->sim_type !== Esim::SIM_TYPE_ESIM) {
            return response()->json([
                'success' => false,
                'message' => 'Activation data is not available for this eSIM.',
                'data' => [
                    'esim' => null,
                    'qr_code_data' => null,
                ],
            ], 404);
        }

        $qrCodeData = trim((string) ($esim->qr_code_data ?? ''));

        if ($qrCodeData === '') {
            return response()->json([
                'success' => false,
                'message' => 'Activation data is not available for this eSIM.',
                'data' => [
                    'esim' => $esim->toUserAssignmentApiArray(),
                    'qr_code_data' => null,
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'esim' => $esim->toUserAssignmentApiArray(),
                'qr_code_data' => $qrCodeData,
            ],
        ]);
    }

    /**
     * Record that the user installed this eSIM profile on their device.
     * Idempotent — safe to call again after the first successful mark.
     */
    public function markDeviceActivated(Request $request, UserEsim $userEsim): JsonResponse
    {
        if ((int) $userEsim->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this eSIM.',
            ], 403);
        }

        $userEsim->loadMissing('esim');
        $esim = $userEsim->esim;

        if (! $esim || $esim->sim_type !== Esim::SIM_TYPE_ESIM) {
            return response()->json([
                'success' => false,
                'message' => 'Device activation tracking is only available for eSIM assignments.',
            ], 422);
        }

        $alreadyActivated = $userEsim->device_activated_at !== null;

        if (! $alreadyActivated) {
            $userEsim->forceFill(['device_activated_at' => now()])->save();
        }

        $userEsim->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

        return response()->json([
            'success' => true,
            'message' => $alreadyActivated
                ? 'eSIM was already marked as activated on your device.'
                : 'eSIM marked as activated on your device.',
            'data' => $userEsim->toAssignmentArray(),
        ], $alreadyActivated ? 200 : 201);
    }

    /**
     * Poll-friendly status after top-up/recharge: has an updated balance arrived since `since`?
     */
    public function balanceStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['required', 'date'],
            'msisdn' => ['nullable', 'string'],
        ]);

        $since = Carbon::parse($data['since']);
        $msisdnFilter = isset($data['msisdn']) && $data['msisdn'] !== ''
            ? Esim::normalizeMsisdn($data['msisdn'])
            : null;

        $assignments = $request->user()
            ->esims()
            ->with('esim')
            ->get();

        foreach ($assignments as $assignment) {
            $esim = $assignment->esim;
            if (! $esim?->msisdn) {
                continue;
            }

            $normalized = Esim::normalizeMsisdn($esim->msisdn);
            if ($msisdnFilter && $normalized !== $msisdnFilter) {
                continue;
            }

            [$fetchedAt, $balances] = $this->latestStoredBalances($assignment, $esim);

            if ($this->balanceIsFresh($fetchedAt, $since, $balances)) {
                $assignment = $this->esimOrderLink->ensureAssignmentLinked($assignment);
                $assignment->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

                return response()->json([
                    'success' => true,
                    'balance_ready' => true,
                    'poll_again' => false,
                    'data' => $assignment->toAssignmentArray(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'balance_ready' => false,
            'poll_again' => true,
            'retry_after_seconds' => 5,
            'data' => null,
        ]);
    }

    /**
     * Poll-friendly status: has the user been assigned a SIM yet? Is inventory available?
     * Frontend can call this every ~5 minutes (no assignment side effects).
     */
    public function assignmentStatus(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $this->evpay->reconcilePendingOrdersForUser((int) $userId);
        $existing = $this->findUserAssignment($userId);

        if ($existing) {
            $existing = $this->esimOrderLink->ensureAssignmentLinked($existing);
            $existing->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

            return $this->assignmentStatusResponse($existing, 'assigned');
        }

        $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);
        $latestPaid = $this->esimOrderLink->latestPaidOrderWithBundles($userId);

        if (! $latestPaid) {
            $pendingOrder = Order::query()
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereIn('payment_status', ['pending', 'pending_payment'])
                        ->orWhere('status', 'pending_payment');
                })
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'success' => false,
                'status' => 'payment_required',
                'has_sim' => false,
                'poll_again' => $pendingOrder !== null,
                'retry_after_seconds' => $pendingOrder ? 5 : 0,
                'message' => 'Complete payment before a SIM can be assigned.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        if (! $this->simAssignment->orderIsPaid($latestPaid)) {
            return response()->json([
                'success' => false,
                'status' => 'payment_required',
                'has_sim' => false,
                'poll_again' => true,
                'retry_after_seconds' => 5,
                'message' => 'Complete payment before a SIM can be assigned.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        if ($latestPaid && $this->simAssignment->orderSimType($latestPaid) === Esim::SIM_TYPE_PHYSICAL) {
            return response()->json([
                'success' => false,
                'status' => 'waiting_for_agent',
                'has_sim' => false,
                'poll_again' => false,
                'message' => 'Physical SIM orders are assigned by an agent after payment.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        $prompt = $this->simAssignment->deferEsimUntilUserConfirms($latestPaid);
        if ($prompt['assigned'] ?? false) {
            $assignment = $prompt['assignment'] ?? null;
            if ($assignment instanceof UserEsim) {
                return $this->assignmentStatusResponse($assignment, 'assigned');
            }
        }

        if (! ($prompt['assigned'] ?? false)) {
            return response()->json([
                'success' => false,
                'status' => $prompt['reason'] === 'activation_pending'
                    ? 'activation_pending'
                    : ($prompt['reason'] === 'scheduled' ? 'scheduled' : 'payment_required'),
                'has_sim' => false,
                'poll_again' => false,
                'activation_date' => $prompt['activation_date'] ?? null,
                'order_id' => $prompt['order_id'] ?? $latestPaid->id,
                'message' => ($prompt['reason'] ?? '') === 'activation_pending'
                    ? 'Your eSIM is being assigned and prepared automatically. No confirmation is needed.'
                    : 'Your eSIM activation date is still ahead.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        $available = $this->simAssignment->availableCount(Esim::SIM_TYPE_ESIM);

        return response()->json([
            'success' => false,
            'status' => 'waiting_for_inventory',
            'has_sim' => false,
            'poll_again' => true,
            'retry_after_seconds' => 5,
            'message' => $available > 0
                ? 'Payment complete. Call POST /me/esims/register to assign your eSIM.'
                : 'Payment complete but no eSIM numbers available yet. Keep polling.',
            'inventory' => [
                'available' => $available,
                'sim_type' => Esim::SIM_TYPE_ESIM,
            ],
            'latest_order' => $latestOrder,
            'data' => null,
        ], 200);
    }

    /**
     * Self-assign: pick the next free active number from inventory for this user only.
     * Safe to poll via POST when GET status shows inventory (or retry every 5 minutes).
     */
    public function register(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $this->evpay->reconcilePendingOrdersForUser((int) $userId);

        $existing = $this->findUserAssignment($userId);

        if ($existing) {
            $existing = $this->esimOrderLink->ensureAssignmentLinked($existing);
            $existing->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

            return $this->registrationResponse(
                $existing,
                false,
                200,
                'already_assigned',
                $this->fulfillLatestPaidOrder($userId),
            );
        }

        try {
            $result = $this->simAssignment->assignEsimForUserIfEligible($userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'status' => 'not_eligible',
                'has_sim' => false,
                'poll_again' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign SIM',
                'error' => $e->getMessage(),
            ], 500);
        }

        if (! ($result['assigned'] ?? false)) {
            $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);

            return response()->json([
                'success' => false,
                'status' => match ($result['reason'] ?? '') {
                    'no_esim_inventory' => 'waiting_for_inventory',
                    'payment_not_paid' => 'payment_required',
                    'scheduled' => 'scheduled',
                    'awaiting_confirmation' => 'awaiting_confirmation',
                    default => 'not_assigned',
                },
                'has_sim' => false,
                'poll_again' => in_array($result['reason'] ?? '', ['no_esim_inventory', 'payment_not_paid'], true),
                'activation_date' => $result['activation_date'] ?? null,
                'order_id' => $result['order_id'] ?? null,
                'retry_after_seconds' => 5,
                'message' => match ($result['reason'] ?? '') {
                    'no_esim_inventory' => 'No eSIM numbers available yet. Retry shortly.',
                    'payment_not_paid' => 'Complete payment before a SIM can be assigned.',
                    default => 'SIM could not be assigned.',
                },
                'inventory' => [
                    'available' => $this->simAssignment->availableCount(Esim::SIM_TYPE_ESIM),
                    'sim_type' => Esim::SIM_TYPE_ESIM,
                ],
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        $assignment = $result['assignment'];
        $created = ($result['reason'] ?? '') === 'assigned';

        return $this->registrationResponse(
            $assignment,
            $created,
            $created ? 201 : 200,
            $created ? 'assigned' : 'already_assigned',
            $result['recharge'] ?? null,
        );
    }

    public function updateActivationDate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'activation_date' => ['required', 'date_format:Y-m-d'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $userId = (int) $request->user()->id;
        $order = null;

        if (! empty($data['order_id'])) {
            $order = Order::query()
                ->where('id', $data['order_id'])
                ->where('user_id', $userId)
                ->first();
        }

        if (! $order) {
            $order = $this->esimOrderLink->latestPaidOrderWithBundles($userId)
                ?? Order::query()
                    ->where('user_id', $userId)
                    ->orderByDesc('id')
                    ->first();
        }

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $order = $this->simAssignment->rescheduleActivationDate($order, $data['activation_date']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'activation_date' => $this->simAssignment->activationDateIso($order),
            'order_id' => $order->id,
            'assignment_prompt' => $this->simAssignment->assignmentPromptForUser($userId),
        ]);
    }

    public function recharges(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');

        return $this->proxy($this->vodacom->get('/api/recharge', $query));
    }

    public function usage(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');

        return $this->proxy($this->vodacom->get('/api/usage', $query));
    }

    public function usageDetails(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');

        return $this->proxy($this->vodacom->get('/api/usage-details', $query));
    }

    public function recharge(MeEsimRechargeRequest $request)
    {
        $data = $request->validated();

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        if (! is_null($esim->esim?->network_id) && (int) $esim->esim->network_id !== (int) $data['network_id']) {
            return response()->json(['message' => 'You do not have access to this eSIM.'], 403);
        }

        $response = $this->postVodacomRecharge(
            $request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id'])
        );

        if ($response->successful() && ! empty($data['msisdn'])) {
            try {
                $this->balances->requestBalancesForMsisdn((string) $data['msisdn']);
            } catch (\Throwable $e) {
                Log::warning('Balance refresh after recharge failed', [
                    'msisdn' => $data['msisdn'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->proxy($response);
    }

    private function registrationResponse(
        UserEsim $assignment,
        bool $created,
        int $status,
        string $assignmentStatus = 'assigned',
        ?array $recharge = null,
    ): JsonResponse {
        $assignment->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

        $recharge ??= $this->fulfillLatestPaidOrder((int) $assignment->user_id);
        app(\App\Services\Esim\EsimActivationEmailService::class)->sendIfEligible($assignment);

        return response()->json([
            'success' => true,
            'status' => $assignmentStatus,
            'has_sim' => true,
            'poll_again' => false,
            'message' => $created ? 'SIM assigned successfully' : 'SIM already assigned',
            'data' => $assignment->toAssignmentArray(),
            'latest_order' => $this->esimOrderLink->latestOrderForUser((int) $assignment->user_id),
            'recharge' => $recharge,
        ], $status);
    }

    private function assignmentStatusResponse(UserEsim $assignment, string $status): JsonResponse
    {
        $assignment->loadMissing(['esim', 'bundle.type', 'order', 'orderItem']);

        return response()->json([
            'success' => true,
            'status' => $status,
            'has_sim' => true,
            'poll_again' => false,
            'message' => 'SIM already assigned',
            'data' => $assignment->toAssignmentArray(),
            'latest_order' => $this->esimOrderLink->latestOrderForUser((int) $assignment->user_id),
        ], 200);
    }

    private function findUserAssignment(int $userId): ?UserEsim
    {
        return UserEsim::query()
            ->where('user_id', $userId)
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->with(['esim', 'bundle.type', 'order', 'orderItem'])
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fulfillLatestPaidOrder(int $userId): ?array
    {
        $order = Order::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere('status', 'paid');
            })
            ->where(function ($q) {
                $q->whereNull('recharge_status')
                    ->orWhereIn('recharge_status', ['pending_esim', 'pending_retry', 'failed']);
            })
            ->orderByDesc('id')
            ->first();

        if (! $order) {
            return null;
        }

        try {
            return $this->orderRecharge->rechargePaidOrder($order);
        } catch (\Throwable $e) {
            Log::error('Order recharge failed after SIM registration', [
                'user_id' => $userId,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
                'recharge_status' => 'failed',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postVodacomRecharge(array $payload)
    {
        return $this->vodacom->post('/api/recharge', [], VodacomRechargePayload::normalize($payload));
    }

    private function requireOwnedEsim(Request $request, string $msisdn): UserEsim
    {
        $esim = $request->user()
            ->esims()
            ->whereHas('esim', fn ($q) => $q->where('msisdn', $msisdn))
            ->with('esim')
            ->first();

        if (! $esim) {
            abort(response()->json(['message' => 'You do not have access to this eSIM.'], 403));
        }

        return $esim;
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }

    /**
     * Prefer the newest stored snapshot on the assignment or inventory row.
     *
     * @return array{0: ?Carbon, 1: array<string, mixed>}
     */
    private function latestStoredBalances(UserEsim $assignment, Esim $esim): array
    {
        $assignmentFetched = $assignment->balance_fetched_at;
        $esimFetched = $esim->balance_fetched_at;
        $useEsim = $esimFetched && (! $assignmentFetched || $esimFetched->gt($assignmentFetched));

        $balances = $useEsim
            ? (is_array($esim->balances) ? $esim->balances : [])
            : (is_array($assignment->balances) ? $assignment->balances : []);

        return [$useEsim ? $esimFetched : $assignmentFetched, $balances];
    }

    /**
     * @param  mixed  $balances
     */
    private function balanceIsFresh(?Carbon $fetchedAt, Carbon $since, $balances): bool
    {
        if (! $fetchedAt || ! $fetchedAt->gt($since)) {
            return false;
        }

        if (! is_array($balances) || $balances === []) {
            return false;
        }

        foreach (['DATA', 'data', 'AIRTIME', 'airtime', 'SMS', 'sms'] as $key) {
            if (! array_key_exists($key, $balances)) {
                continue;
            }

            $value = $balances[$key];
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
