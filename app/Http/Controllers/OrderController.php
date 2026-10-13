<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Esim;
use App\Models\Kyc;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Trip;
use App\Models\UserEsim;
use App\Services\Esim\PhysicalSimIssuanceService;
use App\Services\Esim\SimAssignmentService;
use App\Services\EvPay\EvPayCheckoutService;
use App\Support\BundleValidity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private readonly EvPayCheckoutService $evpay,
        private readonly PhysicalSimIssuanceService $physicalIssuance,
        private readonly SimAssignmentService $simAssignment,
    ) {}

    public function getOrders(): JsonResponse
    {
        $orders = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $this->evpay->reconcilePendingOrdersForUser((int) $request->user()->id);

        $orders = Order::with(['trip', 'orderItems.bundle', 'kyc'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function getOrdersByUser(int $userId): JsonResponse
    {
        $orders = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Search an order by order number (draft_id), e.g. DRAFT-2026-001.
     * Admin: any order. Agent: any order. Customer: own orders only.
     */
    public function searchByOrderNumber(Request $request): JsonResponse
    {
        $draftId = $this->resolveOrderNumber($request);
        if ($draftId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Provide draft_id or order_number query parameter.',
            ], 422);
        }

        $user = $request->user();
        $query = Order::query()
            ->with(['orderItems', 'user'])
            ->where('draft_id', $draftId);

        if ($user && ! $user->isAdmin() && ! $user->isAgent()) {
            $query->where('user_id', $user->id);
        }

        $order = $query->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOrderSearchPayload($order),
        ]);
    }

    /**
     * Create or complete an order in one step (merged draft + finalize).
     */
    public function storeOrder(StoreOrderRequest $request): JsonResponse
    {
        $v = $request->validated();

        try {
            DB::beginTransaction();

            $order = Order::where('draft_id', $v['draft_id'])
                ->lockForUpdate()
                ->first();

            $newStatus = 'pending_payment';

            $meta = array_merge($order ? $this->metadataArray($order) : [], [
                'created_at' => $v['order_metadata']['created_at'],
                'checkoutMode' => $v['checkoutMode'] ?? null,
                'country' => $v['country'] ?? null,
                'countryName' => $v['countryName'] ?? null,
                'simType' => $v['simType'] ?? null,
                'finalized_at' => now()->toIso8601String(),
            ]);
            foreach (['msisdn', 'esim_id', 'user_esim_id'] as $simKey) {
                if (! empty($v[$simKey])) {
                    $meta[$simKey] = $v[$simKey];
                }
            }
            $meta['status'] = $newStatus;
            if (! empty($v['payment'] ?? [])) {
                $meta['payment'] = array_filter([
                    'status' => data_get($v, 'payment.status'),
                    'reference' => data_get($v, 'payment.reference'),
                    'method' => data_get($v, 'payment.method'),
                    'paid_at' => data_get($v, 'payment.paid_at'),
                ], fn ($x) => $x !== null && $x !== '');
            }

            if ($order && $order->status !== 'draft') {
                DB::commit();
                $order->load(['trip', 'orderItems', 'user', 'kyc']);

                $existingCheckoutUrl = null;
                $existingPaymentRef = $order->payment_reference;
                if ($order->payment_status !== 'paid') {
                    $checkout = $this->evpay->startCardPayment($order);
                    $existingCheckoutUrl = $checkout['checkout_url'] ?? $checkout['payment_url'] ?? null;
                    $existingPaymentRef = $checkout['payment_reference'] ?? $existingPaymentRef;
                    $order->refresh();
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order already exists',
                    'data' => [
                        'order' => $order,
                        'draft_id' => $order->draft_id,
                        'status' => $order->status,
                        'total_amount' => $order->total_amount,
                        'currency' => $order->currency,
                        'payment_reference' => $existingPaymentRef,
                        'checkout_url' => $existingCheckoutUrl,
                        'payment_url' => $existingCheckoutUrl,
                    ],
                ], 200);
            }

            $validatedForKyc = [
                'user_id' => $v['user_id'],
                'kyc' => $v['kyc'],
                'trip' => $v['trip'],
            ];
            $this->createOrUpdateKyc($validatedForKyc);

            $orderPayload = [
                'draft_id' => $v['draft_id'],
                'user_id' => $v['user_id'],
                'status' => $newStatus,
                'subtotal' => $v['pricing']['subtotal'],
                'discount_amount' => $v['pricing']['discount_amount'],
                'discount_code' => $v['pricing']['discount_code'] ?? null,
                'total_amount' => $v['pricing']['total_amount'],
                'currency' => $v['pricing']['currency'],
                'source' => $v['order_metadata']['source'],
                'platform' => $v['order_metadata']['platform'],
                'metadata' => $meta,
            ];

            if ($order && $order->payment_status === 'paid') {
                $orderPayload['status'] = 'paid';
                $orderPayload['payment_status'] = 'paid';
                $orderPayload['paid_at'] = $order->paid_at;
                $orderPayload['payment_reference'] = $order->payment_reference;
            }

            if (! $order) {
                $order = Order::create($orderPayload);
                $this->createTrip($order, $v['trip']);
                $this->createOrderItems($order, $v['items']);
            } else {
                $order->update($orderPayload);
                $this->updateTrip($order, $v['trip']);
                $order->orderItems()->delete();
                $this->createOrderItems($order, $v['items']);
            }

            DB::commit();

            $order->refresh();
            $order->load(['trip', 'orderItems', 'user', 'kyc']);

            $checkoutUrl = null;
            $paymentRef = $order->payment_reference;
            $assignResult = null;
            if ($order->payment_status !== 'paid') {
                $checkout = $this->evpay->startCardPayment($order);
                $checkoutUrl = $checkout['checkout_url'] ?? $checkout['payment_url'] ?? null;
                $paymentRef = $checkout['payment_reference'] ?? $paymentRef;
            }

            return response()->json([
                'success' => true,
                'message' => 'Order stored successfully',
                'data' => [
                    'order' => $order,
                    'draft_id' => $order->draft_id,
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'currency' => $order->currency,
                    'payment_reference' => $paymentRef,
                    'checkout_url' => $checkoutUrl,
                    'payment_url' => $checkoutUrl,
                    'sim_assignment' => $this->simAssignment->assignmentSummary($assignResult),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to store order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(string $draftId): JsonResponse
    {
        $order = Order::with(['trip', 'orderItems.bundle', 'user', 'kyc'])
            ->where('draft_id', $draftId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update the specified order.
     */
    public function update(StoreOrderRequest $request, string $draftId): JsonResponse
    {
        try {
            DB::beginTransaction();

            $order = Order::where('draft_id', $draftId)->first();

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $validated = $request->validated();

            // Update KYC record
            $this->createOrUpdateKyc($validated);

            // Update the order
            $this->updateOrder($order, $validated);

            // Update trip record
            $this->updateTrip($order, $validated['trip']);

            // Update order items (delete old ones and create new ones)
            $order->orderItems()->delete();
            $this->createOrderItems($order, $validated['items']);

            DB::commit();

            // Load relationships for response
            $order->load(['trip', 'orderItems', 'user', 'kyc']);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified order from storage.
     */
    public function destroy(string $draftId): JsonResponse
    {
        try {
            $order = Order::where('draft_id', $draftId)->first();

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Order deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update KYC record
     */
    private function createOrUpdateKyc(array $validated): Kyc
    {
        $kycData = $validated['kyc'];

        $kyc = Kyc::updateOrCreate(
            ['user_id' => $validated['user_id']],
            [
                'passport_id' => $kycData['passport_id'],
                'passport_country' => $kycData['passport_country'],
                'nationality' => $kycData['nationality'],
                'gender' => $kycData['gender'],
                'reason' => $kycData['reason_for_travel'],
                'arrival_date' => $validated['trip']['arrival_date'],
                'departure_date' => $validated['trip']['departure_date'],
            ]
        );

        return $kyc;
    }

    /**
     * Create order record
     */
    private function createOrder(array $validated): Order
    {
        $pricing = $validated['pricing'];
        $metadata = $validated['order_metadata'] ?? [];

        return Order::create([
            'draft_id' => $validated['draft_id'],
            'user_id' => $validated['user_id'],
            'status' => $metadata['status'] ?? 'pending_payment',
            'subtotal' => $pricing['subtotal'],
            'discount_amount' => $pricing['discount_amount'] ?? 0,
            'discount_code' => $pricing['discount_code'],
            'total_amount' => $pricing['total_amount'],
            'currency' => $pricing['currency'],
            'source' => $metadata['source'] ?? null,
            'platform' => $metadata['platform'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Update order record
     */
    private function updateOrder(Order $order, array $validated): void
    {
        $pricing = $validated['pricing'];
        $metadata = $validated['order_metadata'] ?? [];

        $order->update([
            'status' => $metadata['status'] ?? $order->status,
            'subtotal' => $pricing['subtotal'],
            'discount_amount' => $pricing['discount_amount'] ?? 0,
            'discount_code' => $pricing['discount_code'],
            'total_amount' => $pricing['total_amount'],
            'currency' => $pricing['currency'],
            'source' => $metadata['source'] ?? $order->source,
            'platform' => $metadata['platform'] ?? $order->platform,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create trip record
     */
    private function createTrip(Order $order, array $tripData): Trip
    {
        $tripData = $this->normalizeEsimDates($order, $tripData);
        return Trip::create([
            'order_id' => $order->id,
            'destination_country' => $tripData['destination_country'],
            'arrival_date' => $tripData['arrival_date'],
            'departure_date' => $tripData['departure_date'],
            'duration_days' => $tripData['duration_days'],
        ]);
    }

    /**
     * Update trip record
     */
    private function updateTrip(Order $order, array $tripData): void
    {
        $tripData = $this->normalizeEsimDates($order, $tripData);
        $trip = $order->trip;

        if ($trip) {
            $trip->update([
                'destination_country' => $tripData['destination_country'],
                'arrival_date' => $tripData['arrival_date'],
                'departure_date' => $tripData['departure_date'],
                'duration_days' => $tripData['duration_days'],
            ]);
        } else {
            $this->createTrip($order, $tripData);
        }
    }

    /**
     * Create order items
     */
    private function normalizeEsimDates(Order $order, array $tripData): array
    {
        if ($this->simAssignment->orderSimType($order) === Esim::SIM_TYPE_ESIM) {
            $tripData['departure_date'] = Carbon::parse($tripData['arrival_date'])
                ->addDays(BundleValidity::DAYS)->toDateString();
            $tripData['duration_days'] = BundleValidity::DAYS;
        }

        return $tripData;
    }

    private function createOrderItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'type' => $item['type'],
                'bundle_id' => $item['bundle_id'] ?? null,
                'bundle_name' => $item['bundle_name'],
                'data_amount' => $item['data_amount'] ?? null,
                'validity_days' => $item['type'] === 'bundle' ? BundleValidity::DAYS : ($item['validity_days'] ?? null),
                'price' => $item['price'],
                'currency' => $item['currency'],
            ]);
        }
    }

    /**
     * Normalize order.metadata to an array (handles array cast and legacy JSON strings).
     *
     * @return array<string, mixed>
     */
    private function resolveOrderNumber(Request $request): ?string
    {
        $value = $request->query('draft_id') ?? $request->query('order_number');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array{
     *     order: array<string, mixed>,
     *     order_items: list<array<string, mixed>>,
     *     user: ?array<string, mixed>,
     *     user_esim: ?array<string, mixed>,
     *     esim: ?array<string, mixed>
     * }
     */
    private function formatOrderSearchPayload(Order $order): array
    {
        $userEsim = $this->resolveUserEsimForOrder($order);
        if ($userEsim) {
            $userEsim->loadMissing(['bundle', 'order', 'orderItem', 'physicalIssuedBy', 'esim']);
        }

        $meta = $this->metadataArray($order);

        $user = $order->user;
        $customerName = $user?->name;
        $customerEmail = $user?->email;

        return [
            'order' => array_merge($order->only([
                'id',
                'draft_id',
                'user_id',
                'status',
                'subtotal',
                'discount_amount',
                'discount_code',
                'total_amount',
                'currency',
                'payment_status',
                'payment_reference',
                'paid_at',
                'recharge_status',
                'created_at',
                'updated_at',
            ]), [
                'sim_type' => $meta['simType'] ?? null,
            ]),
            'order_items' => $order->orderItems
                ->map(fn (OrderItem $item) => $item->only([
                    'id',
                    'order_id',
                    'type',
                    'bundle_id',
                    'bundle_name',
                    'data_amount',
                    'validity_days',
                    'price',
                    'currency',
                ]))
                ->values()
                ->all(),
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'user' => $order->user?->only(['id', 'name', 'email', 'role']),
            'user_esim' => $userEsim ? array_merge(
                $userEsim->only([
                    'id',
                    'user_id',
                    'esim_id',
                    'bundle_id',
                    'order_id',
                    'order_item_id',
                    'balance',
                    'balance_currency',
                    'last_recharge_amount',
                    'last_recharge_reference',
                    'last_recharge_status',
                    'last_recharged_at',
                    'physical_issued_at',
                    'physical_issued_by',
                    'physical_issued_location',
                ]),
                [
                    'bundle' => $userEsim->bundleWithDuration(),
                ],
                $this->physicalIssuance->issuancePayload($userEsim),
            ) : null,
            'esim' => $userEsim?->esim?->only([
                'id',
                'sim_id',
                'msisdn',
                'iccid',
                'imsi',
                'sim_type',
                'status',
                'provider_status',
                'network_id',
            ]),
        ];
    }

    private function resolveUserEsimForOrder(Order $order): ?UserEsim
    {
        $meta = $this->metadataArray($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::with(['esim', 'bundle'])->find($meta['user_esim_id']);
            if ($assignment) {
                return $assignment;
            }
        }

        if (! empty($meta['esim_id'])) {
            $assignment = UserEsim::with(['esim', 'bundle'])
                ->where('esim_id', $meta['esim_id'])
                ->when($order->user_id, fn ($q) => $q->where('user_id', $order->user_id))
                ->first();
            if ($assignment) {
                return $assignment;
            }
        }

        if (! empty($meta['msisdn'])) {
            $esim = Esim::findByMsisdn((string) $meta['msisdn']);
            if ($esim) {
                $assignment = UserEsim::with(['esim', 'bundle'])
                    ->where('esim_id', $esim->id)
                    ->when($order->user_id, fn ($q) => $q->where('user_id', $order->user_id))
                    ->first();
                if ($assignment) {
                    return $assignment;
                }
            }
        }

        if ($order->user_id) {
            return UserEsim::with(['esim', 'bundle'])
                ->where('user_id', $order->user_id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    private function metadataArray(Order $order): array
    {
        $raw = $order->getAttributes()['metadata'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $order->metadata;

        return is_array($value) ? $value : [];
    }
}
