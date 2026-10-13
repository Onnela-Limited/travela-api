<?php

namespace App\Services\Esim;

use App\Models\Esim;
use App\Models\Order;
use App\Models\Trip;
use App\Models\UserEsim;
use App\Support\OrderCheckout;
use App\Support\ActivationSchedule;
use App\Support\BundleValidity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SimAssignmentService
{
    public function __construct(
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly OrderRechargeService $orderRecharge,
        private readonly EsimActivationEmailService $activationEmail,
        private readonly EsimAssignmentDueEmailService $assignmentDueEmail,
    ) {}

    public function orderIsPaid(Order $order): bool
    {
        return $order->payment_status === 'paid' || $order->status === 'paid';
    }

    public function orderSimType(Order $order): ?string
    {
        $meta = $this->orderMetadata($order);
        $simType = $meta['simType'] ?? null;

        return in_array($simType, [Esim::SIM_TYPE_ESIM, Esim::SIM_TYPE_PHYSICAL], true)
            ? $simType
            : null;
    }

    /**
     * After payment: assign inventory for new purchases, or recharge only for dashboard top-ups.
     *
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{
     *     assigned: bool,
     *     sim_type: ?string,
     *     reason: string,
     *     assignment?: UserEsim,
     *     recharge?: array<string, mixed>|null
     * }
     */
    public function fulfillPaidOrder(Order $order, ?array $evpayContext = null): array
    {
        if (OrderCheckout::isTopUpOrder($order)) {
            return $this->fulfillTopUpPaidOrder($order, $evpayContext);
        }

        if ($this->orderSimType($order) === Esim::SIM_TYPE_ESIM) {
            return $this->deferEsimUntilUserConfirms($order);
        }

        return $this->assignForPaidOrder($order);
    }

    /**
     * Dashboard top-up: use the customer's existing SIM, skip inventory assignment.
     *
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array{
     *     assigned: bool,
     *     sim_type: ?string,
     *     reason: string,
     *     assignment?: UserEsim,
     *     recharge?: array<string, mixed>|null
     * }
     */
    public function fulfillTopUpPaidOrder(Order $order, ?array $evpayContext = null): array
    {
        $order->refresh();

        if (! $this->orderIsPaid($order)) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'payment_not_paid',
            ];
        }

        $assignment = $this->resolveTopUpAssignment($order);
        if (! $assignment?->esim) {
            Log::warning('Top-up fulfillment failed: no assigned SIM for user', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);

            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'no_esim',
            ];
        }

        $assignment = $this->esimOrderLink->linkAssignmentToOrder($assignment, $order)
            ->load(['esim', 'bundle', 'order', 'orderItem']);
        $this->updateOrderSimMetadata($order, $assignment);

        $recharge = $this->rechargeOrderSafely($order, $evpayContext);

        return [
            'assigned' => true,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'reason' => 'existing_sim',
            'assignment' => $assignment,
            'recharge' => $recharge,
        ];
    }

    /**
     * After payment: auto-assign eSIM inventory only. Physical orders wait for an agent.
     *
     * @return array{
     *     assigned: bool,
     *     sim_type: ?string,
     *     reason: string,
     *     assignment?: UserEsim,
     *     recharge?: array<string, mixed>|null
     * }
     */
    public function assignForPaidOrder(Order $order): array
    {
        $order->refresh();

        if (! $this->orderIsPaid($order)) {
            return [
                'assigned' => false,
                'sim_type' => $this->orderSimType($order),
                'reason' => 'payment_not_paid',
            ];
        }

        $simType = $this->orderSimType($order);
        if (! $simType) {
            return [
                'assigned' => false,
                'sim_type' => null,
                'reason' => 'sim_type_missing',
            ];
        }

        if ($simType === Esim::SIM_TYPE_PHYSICAL) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_PHYSICAL,
                'reason' => 'physical_requires_agent',
            ];
        }

        $existing = $this->findAssignmentForOrder($order);
        if ($existing) {
            return [
                'assigned' => true,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'already_assigned',
                'assignment' => $existing->loadMissing(['esim', 'bundle', 'order', 'orderItem']),
            ];
        }

        try {
            $assignment = $this->assignEsimFromInventory($order);
        } catch (\Throwable $e) {
            Log::warning('eSIM auto-assign failed after payment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'no_esim_inventory',
            ];
        }

        return [
            'assigned' => true,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'reason' => 'assigned',
            'assignment' => $assignment,
        ];
    }

    /**
     * Agent assigns a specific physical ICCID after confirming the order is paid.
     */
    public function assignPhysicalSimToPaidOrder(Order $order, Esim $esim): UserEsim
    {
        $order->refresh();
        $esim->refresh();

        if (! $this->orderIsPaid($order)) {
            throw ValidationException::withMessages([
                'draft_id' => ['Order must be paid before assigning a SIM.'],
            ]);
        }

        if ($this->orderSimType($order) !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'simType' => ['This order is not a physical SIM order.'],
            ]);
        }

        if ($esim->sim_type !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'iccid' => ['The selected inventory record is not a physical SIM.'],
            ]);
        }

        if (UserEsim::where('esim_id', $esim->id)->exists()) {
            throw ValidationException::withMessages([
                'iccid' => ['This SIM is already assigned to another user.'],
            ]);
        }

        $existingForOrder = $this->findAssignmentForOrder($order);
        if ($existingForOrder) {
            throw ValidationException::withMessages([
                'draft_id' => ['This order already has a SIM assignment.'],
            ]);
        }

        if (! $order->user_id) {
            throw ValidationException::withMessages([
                'draft_id' => ['Order has no customer user_id.'],
            ]);
        }

        $assignment = DB::transaction(function () use ($order, $esim) {
            $lockedEsim = Esim::query()->whereKey($esim->id)->lockForUpdate()->firstOrFail();

            if (UserEsim::where('esim_id', $lockedEsim->id)->exists()) {
                throw ValidationException::withMessages([
                    'iccid' => ['This SIM was just assigned by another request.'],
                ]);
            }

            $assignment = UserEsim::create([
                'user_id' => $order->user_id,
                'esim_id' => $lockedEsim->id,
            ]);

            $lockedEsim->update(['status' => 'MANAGED']);

            return $this->esimOrderLink->linkAssignmentToOrder($assignment, $order)
                ->load(['esim', 'bundle', 'order', 'orderItem']);
        });

        $this->updateOrderSimMetadata($order, $assignment);

        return $assignment;
    }

    /**
     * Self-service fallback for eSIM orders only (paid + simType esim).
     * Assigns inventory if needed and recharges Vodacom on the same call.
     */
    public function assignEsimForUserIfEligible(int $userId): array
    {
        $order = $this->esimOrderLink->latestPaidOrderWithBundles($userId);
        if (! $order) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'payment_not_paid',
            ];
        }

        if ($this->orderSimType($order) === Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'simType' => ['Physical SIM orders are assigned by an agent at pickup.'],
            ]);
        }

        if ($this->orderSimType($order) !== Esim::SIM_TYPE_ESIM) {
            throw ValidationException::withMessages([
                'simType' => ['Order simType must be esim for self-service assignment.'],
            ]);
        }

        if (! $this->activationDateIsDue($order)) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'scheduled',
                'activation_date' => $this->activationDateIso($order),
                'order_id' => $order->id,
            ];
        }

        $result = $this->assignForPaidOrder($order);

        if (($result['assigned'] ?? false) && $this->orderIsPaid($order)) {
            $result['recharge'] = $this->rechargeOrderSafely($order->fresh());
        }

        $result['activation_date'] = $this->activationDateIso($order);
        $result['order_id'] = $order->id;

        return $result;
    }

    /**
     * Paid eSIM orders wait for the customer to confirm before a number is attached.
     *
     * @return array{
     *     assigned: bool,
     *     sim_type: string,
     *     reason: string,
     *     assignment?: UserEsim,
     *     activation_date: ?string,
     *     order_id: int
     * }
     */
    public function deferEsimUntilUserConfirms(Order $order): array
    {
        $order->refresh();

        if (! $this->orderIsPaid($order)) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'payment_not_paid',
                'activation_date' => $this->activationDateIso($order),
                'order_id' => $order->id,
            ];
        }

        $existing = $this->findAssignmentForOrder($order);
        if ($existing) {
            return [
                'assigned' => true,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'already_assigned',
                'assignment' => $existing->loadMissing(['esim', 'bundle', 'order', 'orderItem']),
                'activation_date' => $this->activationDateIso($order),
                'order_id' => $order->id,
            ];
        }

        $due = $this->activationDateIsDue($order);

        return [
            'assigned' => false,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'reason' => $due ? 'activation_pending' : 'scheduled',
            'activation_date' => $this->activationDateIso($order),
            'order_id' => $order->id,
        ];
    }

    /**
     * Dashboard prompt for the latest paid eSIM order that still has no number.
     *
     * @return array{status: string, activation_date: ?string, order_id: int}|null
     */
    public function assignmentPromptForUser(int $userId): ?array
    {
        $order = $this->esimOrderLink->latestPaidOrderWithBundles($userId);
        if (! $order || OrderCheckout::isTopUpOrder($order)) {
            return null;
        }

        if ($this->orderSimType($order) !== Esim::SIM_TYPE_ESIM) {
            return null;
        }

        if ($this->findAssignmentForOrder($order)) {
            return null;
        }

        $due = $this->activationDateIsDue($order);

        return [
            'status' => $due ? 'activation_pending' : 'scheduled',
            'activation_date' => $this->activationDateIso($order),
            'order_id' => $order->id,
        ];
    }

    public function notifyAssignmentDue(Order $order): void
    {
        $iso = $this->activationDateIso($order);
        if ($iso === null || ! $this->activationDateIsDue($order)) {
            return;
        }

        try {
            $this->assignmentDueEmail->sendIfEligible($order, $iso);
        } catch (\Throwable $e) {
            Log::warning('eSIM assignment-due email dispatch failed', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function activationDateIso(Order $order): ?string
    {
        $order->loadMissing('trip');
        $date = $order->trip?->arrival_date;
        if ($date === null) {
            return null;
        }

        return $date instanceof \DateTimeInterface
            ? Carbon::instance(\DateTime::createFromInterface($date))->toDateString()
            : Carbon::parse((string) $date)->toDateString();
    }

    public function activationDateIsDue(Order $order): bool
    {
        $iso = $this->activationDateIso($order);
        if ($iso === null) {
            return true;
        }

        return now(ActivationSchedule::TIMEZONE)->greaterThanOrEqualTo(
            Carbon::parse($iso.' '.ActivationSchedule::REMINDER_TIME, ActivationSchedule::TIMEZONE)
        );
    }

    public function rescheduleActivationDate(Order $order, string $iso): Order
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            throw ValidationException::withMessages([
                'activation_date' => ['Choose a valid eSIM activation date.'],
            ]);
        }

        if ($iso < ActivationSchedule::today()) {
            throw ValidationException::withMessages([
                'activation_date' => ['eSIM activation date cannot be in the past.'],
            ]);
        }

        if (OrderCheckout::isTopUpOrder($order) || $this->orderSimType($order) !== Esim::SIM_TYPE_ESIM) {
            throw ValidationException::withMessages([
                'activation_date' => ['This order does not use an eSIM activation date.'],
            ]);
        }

        if ($this->findAssignmentForOrder($order)) {
            throw ValidationException::withMessages([
                'activation_date' => ['A number is already assigned for this order.'],
            ]);
        }

        $order->loadMissing(['trip', 'orderItems']);
        $trip = $order->trip;
        if (! $trip) {
            $trip = new Trip(['order_id' => $order->id]);
        }

        $spanDays = BundleValidity::DAYS;

        $arrival = Carbon::parse($iso)->startOfDay();
        $trip->arrival_date = $arrival->toDateString();
        $trip->departure_date = $arrival->copy()->addDays($spanDays)->toDateString();
        $trip->duration_days = $spanDays;
        $trip->order_id = $order->id;
        $trip->save();

        return $order->fresh(['trip']);
    }

    public function findAssignmentForOrder(Order $order): ?UserEsim
    {
        $meta = $this->orderMetadata($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::with('esim')->find($meta['user_esim_id']);
            if ($assignment) {
                return $assignment;
            }
        }

        return UserEsim::query()
            ->where('order_id', $order->id)
            ->with(['esim', 'bundle', 'order', 'orderItem'])
            ->first();
    }

    public function availableCount(string $simType = Esim::SIM_TYPE_ESIM): int
    {
        return $this->nextAvailableQuery($simType)->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentSummary(?array $result): array
    {
        if (! $result) {
            return [
                'assigned' => false,
                'sim_type' => null,
                'reason' => 'not_processed',
            ];
        }

        $summary = [
            'assigned' => (bool) ($result['assigned'] ?? false),
            'sim_type' => $result['sim_type'] ?? null,
            'reason' => $result['reason'] ?? 'unknown',
        ];

        if (! empty($result['assignment']) && $result['assignment'] instanceof UserEsim) {
            $assignment = $result['assignment'];
            $summary['user_esim_id'] = $assignment->id;
            $summary['msisdn'] = $assignment->esim?->msisdn;
            $summary['iccid'] = $assignment->esim?->iccid;
        }

        return $summary;
    }

    private function assignEsimFromInventory(Order $order): UserEsim
    {
        if (! $order->user_id) {
            throw new \RuntimeException('Order has no user_id.');
        }

        return DB::transaction(function () use ($order) {
            $existing = UserEsim::query()
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $assignment = $this->esimOrderLink->linkAssignmentToOrder($existing, $order)
                    ->load(['esim', 'bundle', 'order', 'orderItem']);

                return $assignment;
            }

            $esim = $this->nextAvailableQuery(Esim::SIM_TYPE_ESIM)->lockForUpdate()->first();
            if (! $esim || UserEsim::where('esim_id', $esim->id)->exists()) {
                throw new \RuntimeException('No available eSIM inventory.');
            }

            $assignment = UserEsim::create([
                'user_id' => $order->user_id,
                'esim_id' => $esim->id,
            ]);

            $esim->update(['status' => 'MANAGED']);

            $assignment = $this->esimOrderLink->linkAssignmentToOrder($assignment, $order);
            $this->updateOrderSimMetadata($order, $assignment);
            $assignment = $assignment->load(['esim', 'bundle', 'order', 'orderItem']);

            return $assignment;
        });
    }

    private function notifyActivationEmail(UserEsim $assignment): void
    {
        try {
            $this->activationEmail->sendIfEligible($assignment);
        } catch (\Throwable $e) {
            Log::warning('eSIM activation email dispatch failed', [
                'user_esim_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nextAvailableQuery(string $simType)
    {
        return Esim::query()
            ->where('sim_type', $simType)
            ->whereNotNull('msisdn')
            ->where('msisdn', '!=', '')
            ->where('provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->whereNotIn('id', UserEsim::query()->select('esim_id'))
            ->orderBy('id');
    }

    private function updateOrderSimMetadata(Order $order, UserEsim $assignment): void
    {
        $assignment->loadMissing('esim');
        $meta = $this->orderMetadata($order);
        $meta['user_esim_id'] = $assignment->id;
        $meta['esim_id'] = $assignment->esim_id;
        if ($assignment->esim?->msisdn) {
            $meta['msisdn'] = $assignment->esim->msisdn;
        }

        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}|null  $evpayContext
     * @return array<string, mixed>|null
     */
    private function rechargeOrderSafely(Order $order, ?array $evpayContext = null): ?array
    {
        try {
            return $this->orderRecharge->rechargePaidOrder($order->fresh(), $evpayContext);
        } catch (\Throwable $e) {
            Log::error('Order recharge failed after SIM assignment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
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

    private function resolveTopUpAssignment(Order $order): ?UserEsim
    {
        if (! $order->user_id) {
            return null;
        }

        $meta = $this->orderMetadata($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::query()
                ->where('id', $meta['user_esim_id'])
                ->where('user_id', $order->user_id)
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['esim_id'])) {
            $assignment = UserEsim::query()
                ->where('user_id', $order->user_id)
                ->where('esim_id', $meta['esim_id'])
                ->with('esim')
                ->first();
            if ($assignment?->esim) {
                return $assignment;
            }
        }

        if (! empty($meta['msisdn']) && is_string($meta['msisdn'])) {
            $esim = Esim::findByMsisdn($meta['msisdn']);
            if ($esim) {
                $assignment = UserEsim::query()
                    ->where('user_id', $order->user_id)
                    ->where('esim_id', $esim->id)
                    ->with('esim')
                    ->first();
                if ($assignment) {
                    return $assignment;
                }
            }
        }

        return UserEsim::query()
            ->where('user_id', $order->user_id)
            ->with('esim')
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderMetadata(Order $order): array
    {
        $raw = $order->getAttributes()['metadata'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $order->metadata;

        return is_array($value) ? $value : [];
    }
}
