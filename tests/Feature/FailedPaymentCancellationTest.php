<?php
namespace Tests\Feature;

use App\Models\{Order,User};
use App\Services\Esim\SimAssignmentService;
use App\Services\EvPay\{EvPayCheckoutService,EvPayClientService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Http,Mail};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FailedPaymentCancellationTest extends TestCase {
    private User $customer;
    protected function setUp(): void {
        parent::setUp();$this->assertSame(':memory:',config('database.connections.sqlite.database'));Http::fake();Http::preventStrayRequests();Mail::fake();
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users',fn(Blueprint $t)=>$t->string('role')->default('user'));
        Schema::create('orders',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('user_id');$t->string('draft_id');$t->string('status');$t->string('payment_status');$t->decimal('total_amount',12,2);$t->string('currency');$t->string('source')->nullable();$t->string('payment_reference')->nullable();$t->string('payment_gateway')->nullable();$t->string('gateway_payment_id')->nullable();
            $t->json('metadata')->nullable();$t->json('payment_payload')->nullable();$t->json('payment_callback')->nullable();$t->timestamp('paid_at')->nullable();$t->string('recharge_status')->nullable();$t->timestamp('recharge_completed_at')->nullable();$t->timestamps();
        });
        Schema::create('user_esims',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');});
        Schema::create('order_items',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->integer('data_amount')->nullable();});
        $this->customer=User::factory()->create();Sanctum::actingAs($this->customer);
    }
    private function failed(array $patch=[]): Order {
        return Order::create(array_merge(['user_id'=>$this->customer->id,'draft_id'=>'DRAFT-'.Str::uuid(),'status'=>'payment_failed','payment_status'=>'failed','total_amount'=>'20.00','currency'=>'USD','payment_reference'=>'ORD-'.Str::random(12),'gateway_payment_id'=>'gateway-test','metadata'=>['pricing_version'=>1],'payment_payload'=>['response'=>['paymentUrl'=>'https://test.example/old-payment']]],$patch));
    }
    private function cancel(Order $order) {return $this->postJson('/api/orders/'.$order->id.'/cancel-failed-payment');}
    public function test_customer_cancels_failed_order_idempotently_without_deleting_payment_history_or_calling_gateway(): void {
        $order=$this->failed();$reference=$order->payment_reference;
        $this->cancel($order)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('order.status','cancelled');$timestamp=$order->fresh()->metadata['customer_cancelled_at'];
        $this->cancel($order)->assertOk()->assertJsonPath('order.customer_cancelled_at',$timestamp);
        $fresh=$order->fresh();$this->assertSame('cancelled',$fresh->payment_status);$this->assertSame('failed',$fresh->metadata['cancelled_payment_status']);$this->assertSame($reference,$fresh->payment_reference);$this->assertNull(data_get($fresh->payment_payload,'response.paymentUrl'));$this->assertSame(1,Order::count());Http::assertNothingSent();
    }
    public function test_other_customers_and_unverified_or_anonymous_users_cannot_cancel(): void {
        $order=$this->failed();Sanctum::actingAs(User::factory()->create());$this->cancel($order)->assertNotFound();
        Sanctum::actingAs(User::factory()->unverified()->create());$this->cancel($order)->assertForbidden();
        $this->app['auth']->forgetGuards();$this->withoutToken();$this->cancel($order)->assertUnauthorized();
        $this->assertSame('failed',$order->fresh()->payment_status);
    }
    public function test_paid_pending_refunded_and_fulfilled_orders_are_protected(): void {
        foreach ([['payment_status'=>'paid','status'=>'paid'],['paid_at'=>now()],['payment_status'=>'pending','status'=>'pending_payment'],['payment_status'=>'refunded','status'=>'refunded'],['recharge_status'=>'success'],['recharge_completed_at'=>now()]] as $patch) $this->cancel($this->failed($patch))->assertConflict();
        $assigned=$this->failed();DB::table('user_esims')->insert(['order_id'=>$assigned->id]);$this->cancel($assigned)->assertConflict();Http::assertNothingSent();
    }
    public function test_bulk_orders_are_not_affected(): void {
        foreach ([['partner_bulk_invoice_id'=>1],['partner_bulk_order_id'=>1]] as $metadata) $this->cancel($this->failed(['metadata'=>$metadata]))->assertConflict();
        $this->cancel($this->failed(['source'=>'partner_bulk']))->assertConflict();
    }
    public function test_cancelled_order_cannot_be_reopened_by_a_retry_or_stale_order_instance(): void {
        $order=$this->failed();$stale=clone $order;$this->cancel($order)->assertOk();
        $this->postJson('/api/orders/'.$order->id.'/prepare-evpay')->assertUnprocessable();$this->postJson('/api/orders/'.$order->id.'/evpay-checkout-url')->assertUnprocessable();
        try {app(EvPayCheckoutService::class)->prepare($stale);$this->fail('Stale order reopened');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('order',$e->errors());}
        $this->assertSame('cancelled',$order->fresh()->payment_status);Http::assertNothingSent();
    }
    public function test_retry_that_has_already_started_blocks_cancellation(): void {
        $order=$this->failed();app(EvPayCheckoutService::class)->prepare($order);$this->cancel($order)->assertConflict();$this->assertSame('pending',$order->fresh()->payment_status);Http::assertNothingSent();
    }
    public function test_late_failure_does_not_reopen_cancelled_order_and_valid_late_success_is_still_fulfilled_once(): void {
        $order=$this->failed();$this->cancel($order)->assertOk();
        $payload=['event'=>'payment.failed','data'=>['orderReference'=>$order->payment_reference,'id'=>'gateway-test','amount'=>20,'currency'=>'USD','status'=>'FAILED']];
        $this->mock(EvPayClientService::class,function($mock)use(&$payload){$mock->shouldReceive('verifiedWebhookPayload')->andReturnUsing(function()use(&$payload){return $payload;});});
        $this->mock(SimAssignmentService::class,function($mock){$mock->shouldReceive('fulfillPaidOrder')->once();});
        $this->postJson('/api/payments/evpay/callback',[],['X-EvPay-Delivery'=>'failed-late'])->assertOk();$this->assertSame('cancelled',$order->fresh()->payment_status);
        $payload['event']='payment.succeeded';$payload['data']['status']='SUCCESS';
        $this->postJson('/api/payments/evpay/callback',[],['X-EvPay-Delivery'=>'paid-late'])->assertOk();$this->postJson('/api/payments/evpay/callback',[],['X-EvPay-Delivery'=>'paid-late'])->assertOk();
        $this->assertSame('paid',$order->fresh()->payment_status);$this->assertNotNull($order->fresh()->paid_at);$this->cancel($order)->assertConflict();Http::assertNothingSent();
    }
    public function test_normal_failed_payment_can_still_retry_and_reuse_the_new_checkout_url(): void {
        foreach (['base_url','client_id','client_secret','signing_key','callback_url','redirect_url','cancel_url'] as $key) config(['services.evpay.'.$key=>'https://test.example/'.$key]);
        $this->mock(EvPayClientService::class,function($mock){$mock->shouldReceive('createCardPayment')->once()->andReturn(['status'=>'PENDING','data'=>['paymentUrl'=>'https://pay.test/secure','reference'=>'gateway-test']]);});
        $order=$this->failed(['payment_payload'=>null]);
        $this->postJson('/api/orders/'.$order->id.'/evpay-checkout-url')->assertOk()->assertJsonPath('payment_url','https://pay.test/secure');
        $this->postJson('/api/orders/'.$order->id.'/evpay-checkout-url')->assertOk()->assertJsonPath('payment_url','https://pay.test/secure');
        $this->assertSame('pending',$order->fresh()->payment_status);$this->cancel($order)->assertConflict();Http::assertNothingSent();
    }
}
