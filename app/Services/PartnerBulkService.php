<?php

namespace App\Services;

use App\Models\{Bundle, Esim, Order, OrderItem, PartnerApplication, PartnerBulkOrder, PartnerStockUnit, Trip, User, UserEsim};
use App\Support\{BundleValidity, BundleVisibility};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerBulkService
{
    public function __construct(private PartnerSalesService $money) {}

    public function eligible(PartnerApplication $application): void
    {
        abort_unless($application->status === 'approved' && in_array(data_get($application->application_payload, 'type'), ['hotel', 'tour_operator', 'event_organiser', 'airline'], true), 403, 'An approved business partner account is required.');
    }

    public function rate(PartnerApplication $application): ?int
    {
        $value = config('partners.bulk_discount_bps.'.data_get($application->application_payload, 'type'));
        return is_int($value) && $value >= 0 && $value <= 5000 ? $value : null;
    }

    public function catalogBundle(Bundle $bundle): bool
    {
        $price = $this->money->cents((string) $bundle->price_usd);
        return $bundle->active && ! BundleVisibility::isAdminOnlyBundle($bundle) && $price > 0 && $price <= 100000000 && ! empty($bundle->sim_bundle_id);
    }

    public function catalog(PartnerApplication $application): array
    {
        $this->eligible($application); $rate = $this->rate($application);
        return ['discountBps' => $rate, 'minimum' => 10, 'maximum' => 500,
            'paymentInstructions' => 'Pay through the secure online checkout, then wait for admin approval. SIM stock is allocated only after approval.',
            'bundles' => Bundle::where('active', true)->orderBy('id')->get()->filter(fn ($bundle) => $this->catalogBundle($bundle))->map(function ($bundle) use ($rate) {
                $price = $this->money->cents((string) $bundle->price_usd);
                $unit = $rate === null ? null : $price - intdiv($price * $rate + 5000, 10000);
                return ['id' => (string) $bundle->id, 'name' => $bundle->name, 'catalogUnit' => $this->money->money($price),
                    'unit' => $unit === null ? null : $this->money->money($unit), 'validityDays' => BundleValidity::DAYS];
            })->values()->all()];
    }

    public function summary(PartnerBulkOrder $order): array
    {
        $order = app(PartnerBulkPaymentService::class)->sync($order);
        return ['id' => (string) $order->id, 'reference' => $order->reference, 'bundle' => $order->bundle_name,
            'simType' => $order->sim_type, 'quantity' => $order->quantity, 'discountBps' => $order->discount_bps,
            'unit' => $this->money->money($order->unit_cents), 'total' => $this->money->money($order->total_cents), 'currency' => 'USD',
            'status' => $order->status, 'version' => $order->version, 'message' => $order->message,
            'checkoutStarted' => $order->payment_order_id !== null,
            'paymentIssue' => in_array($order->status,['paid','approved','rejected','fulfilled']) && ! app(PartnerBulkPaymentService::class)->clear($order),
            'paymentReference' => $order->payment_reference, 'receivedCurrency' => $order->received_currency,
            'receivedAmount' => $order->received_cents === null ? null : $this->money->money($order->received_cents),
            'createdAt' => $order->created_at->toIso8601String(), 'paidAt' => $order->paid_at?->toIso8601String()];
    }

    public function create(PartnerApplication $application, array $data): PartnerBulkOrder
    {
        return DB::transaction(function () use ($application, $data) {
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail(); $this->eligible($application);
            $existing = PartnerBulkOrder::where('partner_application_id', $application->id)->where('request_key', strtolower($data['request_key']))->first();
            if ($existing) return $existing;
            $bundle = Bundle::whereKey($data['bundle_id'])->lockForUpdate()->first();
            $rate = $this->rate($application);
            if (! $bundle || ! $this->catalogBundle($bundle) || $rate === null) throw ValidationException::withMessages(['bundle_id' => 'Choose an available bundle with an agreed business rate.']);
            $catalog = $this->money->cents((string) $bundle->price_usd);
            $unit = $catalog - intdiv($catalog * $rate + 5000, 10000);
            $total = $unit * (int) $data['quantity'];
            if ($total < 100) throw ValidationException::withMessages(['quantity' => 'Online checkout requires a total of at least USD 1.00. Increase the quantity.']);
            if ($total !== $this->money->cents($data['expected_total'])) throw ValidationException::withMessages(['expected_total' => 'The price has changed. Refresh and check the total before ordering.']);
            $order = PartnerBulkOrder::create(['partner_application_id' => $application->id, 'bundle_id' => $bundle->id,
                'request_key' => strtolower($data['request_key']), 'reference' => 'PB-'.Str::upper((string) Str::uuid()),
                'bundle_name' => $bundle->name, 'bundle_product_id' => (string) $bundle->sim_bundle_id, 'network_id' => $bundle->network_id ?? Esim::defaultNetworkId(),
                'validity_days' => BundleValidity::DAYS, 'data_mb' => $bundle->data_mb ?? $bundle->bundle_size_in_mb,
                'sim_type' => $data['sim_type'], 'quantity' => $data['quantity'], 'catalog_unit_cents' => $catalog,
                'discount_bps' => $rate, 'unit_cents' => $unit, 'total_cents' => $total, 'status' => 'pending_payment']);
            $this->event($order, empty($data['team_actor']) ? 'partner' : 'staff', $data['team_actor'] ?? $application->id, 'created'); return $order;
        }, 3);
    }

    public function inventory(PartnerBulkOrder $order): Builder
    {
        return Esim::availableForAssignment()->where('provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->where('network_id', $order->network_id)->where('sim_type', $order->sim_type)->whereNotNull('iccid')->where('iccid', '!=', '')
            ->whereNotNull('msisdn')->where('msisdn', '!=', '')->when($order->sim_type === 'esim', fn ($q) => $q->whereNotNull('qr_code_data')->where('qr_code_data', '!=', ''));
    }

    public function review(int $id, User $admin, array $data): PartnerBulkOrder
    {
        abort_unless(in_array($data['decision'],['approved','rejected','fulfilled','cancelled'],true),422,'Only verified online payments can mark an order paid.');
        try {
            return DB::transaction(function () use ($id, $admin, $data) {
                $appId = PartnerBulkOrder::findOrFail($id)->partner_application_id;
                $application = PartnerApplication::whereKey($appId)->lockForUpdate()->firstOrFail();
                $order = PartnerBulkOrder::whereKey($id)->lockForUpdate()->firstOrFail();
                abort_unless($order->version === (int) $data['expected_version'], 409, 'Order changed. Reload before continuing.');
                $decision = $data['decision'];
                if ($decision === 'approved') {
                    $this->eligible($application);
                    abort_unless($order->status === 'paid',409,'Only paid purchases awaiting approval can be approved.');
                    abort_unless(app(PartnerBulkPaymentService::class)->clear($order),422,'The gateway payment needs review before approval.');
                    $order->status = 'approved';
                } elseif ($decision === 'rejected') {
                    abort_unless(in_array($order->status,['paid','approved'],true),409,'Only paid purchases without allocated stock can be rejected.');
                    $order->status = 'rejected';
                } elseif ($decision === 'fulfilled') {
                    $this->eligible($application);
                    abort_unless($order->status === 'approved', 409, 'Admin approval is required before allocating stock.');
                    abort_unless(app(PartnerBulkPaymentService::class)->clear($order),422,'The gateway payment needs review. Do not allocate stock.');
                    $units = $this->inventory($order)->orderBy('id')->lockForUpdate()->limit($order->quantity)->get();
                    if ($units->count() !== $order->quantity) throw ValidationException::withMessages(['stock' => 'There are not enough matching SIMs. Payment remains recorded; add inventory and retry allocation.']);
                    foreach ($units as $esim) {
                        $esim->forceFill(['partner_bulk_order_id' => $order->id, 'status' => 'MANAGED', 'sale_status' => 'sold'])->save();
                        PartnerStockUnit::create(['partner_bulk_order_id' => $order->id, 'esim_id' => $esim->id]);
                    }
                    $order->status = 'fulfilled';
                } else {
                    abort_unless($order->status === 'pending_payment' && ! $order->payment_order_id, 409, 'Only orders without a started checkout can be cancelled.'); $order->status = 'cancelled';
                }
                $order->message = trim($data['message'] ?? '') ?: null; $order->version++; $order->save();
                $this->event($order, 'admin', $admin->id, $decision, null, $order->message);
                app(PartnerNotificationService::class)->record($application,'bulk:'.$order->id.':'.$order->version,$decision==='fulfilled'?'stock':'bulk',$decision==='fulfilled'?'Stock available':'Bulk purchase update',
                    'Bulk purchase '.$order->reference.($decision==='fulfilled'?' has stock ready to assign.':' is now '.$order->status.'. Open the purchase for details.'),$decision==='fulfilled'?'/dashboard/stock':'/dashboard/bulk');return $order;
            }, 3);
        } catch (UniqueConstraintViolationException) { throw ValidationException::withMessages(['payment_reference' => 'This payment reference is already recorded. Check the order history.']); }
    }

    public function units(PartnerApplication $application): Builder
    {
        return PartnerStockUnit::whereIn('partner_bulk_order_id', PartnerBulkOrder::where('partner_application_id', $application->id)->select('id'));
    }

    public function stock(PartnerStockUnit $unit): array
    {
        $unit->loadMissing(['bulkOrder', 'esim', 'assignment', 'order']);
        $expires = $unit->order?->recharge_status === 'success' ? $unit->order->recharge_completed_at?->copy()->addDays($unit->bulkOrder->validity_days) : null;
        $state = $unit->user_id ? ($unit->handed_over_at ? 'handed_over' : 'assigned') : 'unassigned';
        if ($unit->assignment?->device_activated_at) $state = 'installed';
        if ($expires && $expires->isPast()) $state = 'expired';
        return ['id' => (string) $unit->id, 'version' => $unit->version, 'bundle' => $unit->bulkOrder->bundle_name,
            'simType' => $unit->bulkOrder->sim_type, 'iccid' => $unit->esim->iccid, 'state' => $state,
            'guest' => $unit->guest, 'bundleReady' => $unit->order?->recharge_status === 'success', 'expiresAt' => $expires?->toIso8601String(),
            'handedOverAt' => $unit->handed_over_at?->toIso8601String()];
    }

    public function assign(PartnerApplication $application, int $id, array $data): PartnerStockUnit
    {
        return DB::transaction(function () use ($application, $id, $data) {
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail(); $this->eligible($application);
            $unit = $this->units($application)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($unit->version === (int) $data['expected_version'] && ! $unit->user_id, 409, 'This SIM has already changed or been assigned. Refresh stock.');
            $bulk = PartnerBulkOrder::whereKey($unit->partner_bulk_order_id)->lockForUpdate()->firstOrFail();
            abort_unless($bulk->status === 'fulfilled', 422, 'Paid stock is required.');
            abort_unless(app(PartnerBulkPaymentService::class)->clear($bulk),422,'The original bulk payment needs review before another SIM can be assigned.');
            $user = User::where('email', strtolower(trim($data['email'])))->where('role', 'user')->whereNotNull('email_verified_at')->lockForUpdate()->first();
            if (! $user || ! $user->kyc()->whereNotNull('passport_id_encrypted')->where('passport_id_encrypted', '!=', '')->whereNotNull('passport_country')->exists() || UserEsim::where('user_id', $user->id)->exists()) throw ValidationException::withMessages(['email' => 'Use a traveller account with a verified email, completed traveller details, and no existing SIM.']);
            $bundle = Bundle::whereKey($bulk->bundle_id)->lockForUpdate()->firstOrFail();
            abort_unless((string) $bundle->sim_bundle_id === $bulk->bundle_product_id && ($bundle->network_id ?? Esim::defaultNetworkId()) == $bulk->network_id && ($bundle->data_mb ?? $bundle->bundle_size_in_mb) == $bulk->data_mb, 422, 'This prepaid bundle has changed. Contact Travela before assigning it.');
            $esim = Esim::whereKey($unit->esim_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $esim->partner_bulk_order_id === $bulk->id && $esim->provider_status === 'active' && ! UserEsim::where('esim_id', $esim->id)->exists(), 409, 'This reserved SIM is unavailable. Contact Travela.');
            $price = $bulk->unit_cents;
            $order = Order::create(['draft_id' => 'PS-'.Str::uuid(), 'user_id' => $user->id, 'status' => 'paid', 'payment_status' => 'paid',
                'subtotal' => '0.00', 'discount_amount' => '0.00',
                'total_amount' => '0.00', 'currency' => 'USD', 'source' => 'partner_bulk', 'platform' => 'partner',
                'payment_gateway' => 'partner_prepaid', 'payment_reference' => 'PSU-'.$unit->id, 'paid_at' => $bulk->paid_at,
                'metadata' => ['pricing_version' => 1, 'simType' => $bulk->sim_type, 'partner_bulk_order_id' => $bulk->id, 'prepaid_unit_cents'=>$price]]);
            $item = OrderItem::create(['order_id' => $order->id, 'bundle_id' => $bulk->bundle_id, 'type' => 'bundle', 'bundle_name' => $bulk->bundle_name,
                'data_amount' => $bulk->data_mb, 'validity_days' => $bulk->validity_days, 'price' => '0.00', 'currency' => 'USD']);
            $arrival = \Illuminate\Support\Carbon::parse($data['arrival']);
            Trip::create(['order_id' => $order->id, 'destination_country' => $data['country'], 'arrival_date' => $arrival->toDateString(), 'departure_date' => $arrival->copy()->addDays($bulk->validity_days)->toDateString(), 'duration_days' => $bulk->validity_days]);
            $assignment = new UserEsim(['user_id' => $user->id, 'esim_id' => $esim->id, 'bundle_id' => $bulk->bundle_id, 'order_id' => $order->id, 'order_item_id' => $item->id]);
            $assignment->partnerStockAssignment = true; $assignment->save();
            $unit->user_id = $user->id; $unit->order_id = $order->id; $unit->user_esim_id = $assignment->id;
            $unit->guest = ['name' => $data['name'], 'email' => strtolower(trim($data['email'])), 'arrival' => $data['arrival']]; $unit->version++; $unit->save();
            $this->event($bulk, empty($data['team_actor']) ? 'partner' : 'staff', $data['team_actor'] ?? $application->id, 'assigned', $unit->id); return $unit;
        }, 3);
    }

    public function handover(PartnerApplication $application, int $id, int $version, ?int $staffActor=null): PartnerStockUnit
    {
        return DB::transaction(function () use ($application, $id, $version, $staffActor) {
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail(); $this->eligible($application);
            $unit = $this->units($application)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($unit->version === $version && $unit->user_id && ! $unit->handed_over_at, 409, 'Refresh stock before recording handover.');
            $unit->handed_over_at = now(); $unit->version++; $unit->save();
            if ($unit->bulkOrder->sim_type === 'physical') UserEsim::whereKey($unit->user_esim_id)->update(['physical_issued_at' => now()]);
            $this->event($unit->bulkOrder, $staffActor ? 'staff' : 'partner', $staffActor ?? $application->id, 'handed_over', $unit->id); return $unit;
        }, 3);
    }

    private function event(PartnerBulkOrder $order, string $type, int $actor, string $action, ?int $unit = null, ?string $message = null): void
    {
        DB::table('partner_bulk_events')->insert(['partner_bulk_order_id' => $order->id, 'stock_unit_id' => $unit,
            'actor_type' => $type, 'actor_id' => $actor, 'action' => $action, 'message' => $message === null ? null : Crypt::encryptString($message), 'created_at' => now()]);
    }
}
