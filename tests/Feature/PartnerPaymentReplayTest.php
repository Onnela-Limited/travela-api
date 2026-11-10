<?php

namespace Tests\Feature;

use App\Models\{Order, PartnerApplication, PartnerBulkOrder, PartnerStockUnit, User};
use App\Services\{PartnerReferralService, PartnerSalesService};
use App\Services\Esim\SimAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;

// Reuses the existing isolated SQLite schema; never runs MySQL migrations.
class PartnerPaymentReplayTest extends PartnerBulkTest
{
    public function test_replayed_referral_payment_keeps_one_sale_and_one_commission(): void
    {
        (require database_path('migrations/2026_10_29_000001_create_partner_referrals.php'))->up();
        Schema::table('orders', fn(Blueprint $t) => $t->unsignedBigInteger('partner_referral_id')->nullable());
        (require database_path('migrations/2026_10_31_000001_add_partner_commission_to_orders.php'))->up();
        $partner = $this->replayPartner();
        $referral = app(PartnerReferralService::class)->ensureFor($partner);
        $order = $this->replayOrder(100);
        $order->forceFill(['partner_referral_id'=>$referral->id,'partner_commission_bps'=>1200])->save();
        $this->mock(SimAssignmentService::class, fn($mock) => $mock->shouldReceive('fulfillPaidOrder')->once());
        $this->replayCallback($order, 'same-delivery')->assertOk();
        $paidAt = $order->fresh()->paid_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->replayCallback($order, 'same-delivery')->assertOk();
        $this->replayCallback($order, 'different-delivery')->assertOk();
        $this->assertSame($paidAt, $order->fresh()->paid_at->toIso8601String());
        $totals = app(PartnerSalesService::class)->overview($partner);
        $this->assertSame(1, Order::count());
        $this->assertSame('12.00', app(PartnerSalesService::class)->sale($order->fresh())['commission']);
        $this->assertSame(1, $totals['totals'][0]['sales30']);
        $this->assertSame('12.00', $totals['totals'][0]['earnedAllTime']);
        Http::assertNothingSent();
    }

    public function test_replayed_bulk_payment_stays_awaiting_approval_without_duplicate_events_or_stock(): void
    {
        $partner = $this->replayPartner();
        $bundle = \App\Models\Bundle::firstOrFail();
        $bulk = PartnerBulkOrder::create(['partner_application_id'=>$partner->id,'bundle_id'=>$bundle->id,'request_key'=>(string)Str::uuid(),'reference'=>'PB-REPLAY-TEST','bundle_name'=>$bundle->name,'bundle_product_id'=>'42','network_id'=>1,'validity_days'=>30,'data_mb'=>10240,'sim_type'=>'esim','quantity'=>10,'catalog_unit_cents'=>2000,'discount_bps'=>500,'unit_cents'=>1900,'total_cents'=>19000,'status'=>'pending_payment','version'=>0]);
        $order = $this->replayOrder(190, ['partner_bulk_invoice_id'=>$bulk->id]);
        $bulk->payment_order_id=$order->id; $bulk->save();
        $this->replayCallback($order,'bulk-same')->assertOk();
        $first = $bulk->fresh();
        $this->assertSame('paid',$first->status);
        $this->assertSame(1,$first->version);
        $paidAt=$first->paid_at->toIso8601String();
        $this->replayCallback($order,'bulk-same')->assertOk();
        $this->replayCallback($order,'bulk-different')->assertOk();
        $this->assertSame('paid',$bulk->fresh()->status);
        $this->assertSame(1,$bulk->fresh()->version);
        $this->assertSame($paidAt,$bulk->fresh()->paid_at->toIso8601String());
        $this->assertSame(1,DB::table('partner_bulk_events')->where('action','paid')->count());
        $this->assertSame(1,DB::table('partner_notifications')->count());
        $this->assertSame(0,PartnerStockUnit::count());
        $this->assertSame(1,Order::count());
        Http::assertNothingSent();
    }

    private function replayPartner(): PartnerApplication
    {
        return PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'status'=>'approved','application_payload'=>['type'=>'hotel','profile'=>['displayName'=>'Replay Test','email'=>'replay@example.test']],'documents'=>[],'submitted_at'=>now()]);
    }
    private function replayOrder(int $amount,array $metadata=[]): Order
    {
        return Order::create(['draft_id'=>'REPLAY-'.Str::uuid(),'user_id'=>User::factory()->create()->id,'status'=>'pending_payment','payment_status'=>'pending','subtotal'=>$amount,'discount_amount'=>0,'total_amount'=>$amount,'currency'=>'USD','payment_gateway'=>'evpay','payment_reference'=>'REPLAY-'.Str::uuid(),'gateway_payment_id'=>'GW-'.Str::uuid(),'metadata'=>['pricing_version'=>1,...$metadata]]);
    }
    private function replayCallback(Order $order,string $delivery)
    {
        $timestamp=(string)time();
        $body=json_encode(['event'=>'payment.succeeded','timestamp'=>(int)$timestamp,'data'=>['id'=>$order->gateway_payment_id,'orderReference'=>$order->payment_reference,'amount'=>(float)$order->total_amount,'currency'=>'USD','status'=>'SUCCESS']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $signature=hash_hmac('sha256',$timestamp.'.'.$body,config('services.evpay.signing_key'));
        return $this->call('POST','/api/payments/evpay/callback',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_EVPAY_SIGNATURE'=>'t='.$timestamp.',v1='.$signature,'HTTP_X_EVPAY_DELIVERY'=>$delivery],$body);
    }
}
