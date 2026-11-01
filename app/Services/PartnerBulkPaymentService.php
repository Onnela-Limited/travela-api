<?php

namespace App\Services;

use App\Models\{Order, PartnerApplication, PartnerBulkOrder, User};
use App\Services\EvPay\EvPayCheckoutService;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerBulkPaymentService
{
    public function __construct(private PartnerSalesService $money) {}

    public function returnUrl(Order $payment, string $outcome): string
    {
        $base = rtrim((string) config('partners.portal_url'),'/'); $parts = parse_url($base);
        if (! $parts || ! in_array($parts['scheme'] ?? '',['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) throw new \RuntimeException('Configure the partner portal return URL.');
        return $base.'/dashboard/bulk?bulk='.(int) data_get($payment->metadata,'partner_bulk_invoice_id').'&payment='.$outcome;
    }

    public function clear(PartnerBulkOrder $bulk): bool
    {
        $payment = $bulk->payment_order_id ? Order::find($bulk->payment_order_id) : null;
        if (! $payment || $payment->payment_status !== 'paid' || ! $payment->paid_at || $payment->payment_gateway !== 'evpay'
            || $payment->currency !== 'USD' || $this->money->cents((string) $payment->total_amount) !== $bulk->total_cents
            || data_get($payment->metadata, 'pricing_version') !== 1 || (int) data_get($payment->metadata, 'partner_bulk_invoice_id') !== $bulk->id) return false;
        $events = collect(data_get($payment->payment_callback, 'deliveries', []))->pluck('event')->push(data_get($payment->payment_callback, 'last.event'));
        return $events->intersect(['payment.refunded','payment.reversed','payment.on_hold'])->isEmpty();
    }

    public function sync(PartnerBulkOrder $bulk): PartnerBulkOrder
    {
        if ($bulk->status !== 'pending_payment' || ! $bulk->payment_order_id) return $bulk;
        return DB::transaction(function () use ($bulk) {
            PartnerApplication::whereKey($bulk->partner_application_id)->lockForUpdate()->firstOrFail();
            $bulk = PartnerBulkOrder::whereKey($bulk->id)->lockForUpdate()->firstOrFail();
            if ($bulk->status !== 'pending_payment' || ! $this->clear($bulk)) return $bulk;
            $payment = Order::findOrFail($bulk->payment_order_id);
            $bulk->status = 'paid'; $bulk->paid_at = $payment->paid_at; $bulk->payment_reference = $payment->payment_reference;
            $bulk->received_currency = 'USD'; $bulk->received_cents = $bulk->total_cents; $bulk->version++; $bulk->save();
            DB::table('partner_bulk_events')->insert(['partner_bulk_order_id'=>$bulk->id,'stock_unit_id'=>null,'actor_type'=>'gateway','actor_id'=>0,'action'=>'paid','message'=>null,'created_at'=>now()]);
            app(PartnerNotificationService::class)->record(PartnerApplication::findOrFail($bulk->partner_application_id),'bulk:'.$bulk->id.':'.$bulk->version,'bulk','Bulk payment received','Payment for bulk purchase '.$bulk->reference.' is recorded. It is awaiting admin approval.','/dashboard/bulk');
            return $bulk;
        },3);
    }

    public function checkout(PartnerApplication $application, int $id): array
    {
        return Cache::lock('partner_bulk_checkout_'.$id, 90)->block(5, function () use ($application,$id) {
            $bulk = DB::transaction(function () use ($application,$id) {
                $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail(); app(PartnerBulkService::class)->eligible($application);
                $bulk = PartnerBulkOrder::whereKey($id)->where('partner_application_id',$application->id)->lockForUpdate()->firstOrFail();
                abort_unless($bulk->status === 'pending_payment',409,'This order is already paid or cancelled. Refresh its status.');
                abort_unless($bulk->total_cents >= 100,422,'Online checkout requires at least USD 1.00.');
                if (! $bulk->payment_order_id) {
                    $email = strtolower(trim((string) data_get($application->application_payload,'profile.email')));
                    if (! filter_var($email,FILTER_VALIDATE_EMAIL)) throw ValidationException::withMessages(['email'=>'The application needs a valid billing email.']);
                    $user = User::where('email',$email)->lockForUpdate()->first();
                    if ($user && $user->role !== 'user') throw ValidationException::withMessages(['email'=>'This billing email belongs to a staff account. Contact Travela to set up a customer billing address.']);
                    if (! $user) $user = User::create(['name'=>data_get($application->application_payload,'profile.displayName','Partner'),'email'=>$email,'role'=>'user','password'=>Str::random(64),'email_verified_at'=>now()]);
                    $payment = Order::create(['draft_id'=>'PBI-'.Str::uuid(),'user_id'=>$user->id,'status'=>'pending_payment','payment_status'=>'pending',
                        'subtotal'=>$this->money->money($bulk->catalog_unit_cents*$bulk->quantity),'discount_amount'=>$this->money->money(($bulk->catalog_unit_cents-$bulk->unit_cents)*$bulk->quantity),
                        'total_amount'=>$this->money->money($bulk->total_cents),'currency'=>'USD','source'=>'partner_bulk_invoice','platform'=>'partner',
                        'metadata'=>['pricing_version'=>1,'partner_bulk_invoice_id'=>$bulk->id]]);
                    // Generate and persist the existing gateway reference before exposing this order.
                    app(EvPayCheckoutService::class)->prepare($payment);
                    $bulk->payment_order_id=$payment->id; $bulk->save();
                }
                return $bulk;
            },3);
            $payment = Order::findOrFail($bulk->payment_order_id);
            $payment = app(EvPayCheckoutService::class)->reconcilePendingOrder($payment);
            $events=collect(data_get($payment->payment_callback,'deliveries',[]))->pluck('event')->push(data_get($payment->payment_callback,'last.event'));
            abort_if($events->intersect(['payment.on_hold','payment.refunded','payment.reversed'])->isNotEmpty(),422,'This payment needs review. Contact Travela before attempting another payment.');
            if ($payment->payment_status === 'paid') { $this->sync($bulk); abort(409,'Payment is already recorded. Refresh the bulk order.'); }
            $response = app(EvPayCheckoutService::class)->startCardPayment($payment);
            $url = $response['checkout_url'] ?? '';
            abort_unless(is_string($url) && filter_var($url,FILTER_VALIDATE_URL) && parse_url($url,PHP_URL_SCHEME)==='https',503,'The payment service did not return a secure checkout URL.');
            return ['checkoutUrl'=>$url];
        });
    }

    public function refresh(PartnerApplication $application, int $id): PartnerBulkOrder
    {
        app(PartnerBulkService::class)->eligible($application);
        $bulk = PartnerBulkOrder::whereKey($id)->where('partner_application_id',$application->id)->firstOrFail();
        if ($bulk->status === 'pending_payment' && $bulk->payment_order_id) app(EvPayCheckoutService::class)->reconcilePendingOrder(Order::findOrFail($bulk->payment_order_id));
        return $this->sync($bulk);
    }
}
