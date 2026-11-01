<?php

namespace Tests\Feature;

use App\Models\{Bundle, Esim, Order, PartnerApplication, PartnerBulkOrder, PartnerStockUnit, User, UserEsim};
use App\Services\{PartnerBulkService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerBulkTest extends TestCase
{
    private Bundle $bundle;
    protected function setUp(): void
    {
        parent::setUp(); $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests(); Mail::fake();
        config(['partners.portal_url'=>'https://partners.example.test']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(),'/api/auth/v1')) return Http::response(['data'=>['accessToken'=>'test-token','expiresIn'=>36000]]);
            if (str_ends_with($request->url(),'/api/v1/payment/card')) return Http::response(['status'=>'PENDING','data'=>['reference'=>'GW-'.$request['orderReference'],'paymentUrl'=>'https://pay.example.test/checkout']]);
            if (str_contains($request->url(),'/api/v1/payment/')) return Http::response(['status'=>'PENDING','data'=>['status'=>'PENDING']]);
            throw new \RuntimeException('Unexpected external request: '.$request->url());
        });
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('user'));
        (require database_path('migrations/2025_10_05_114523_create_personal_access_tokens_table.php'))->up();
        foreach (['2026_10_25_000001_create_partner_applications_table', '2026_10_26_000001_add_partner_authentication'] as $m) (require database_path("migrations/$m.php"))->up();
        (require database_path('migrations/2026_11_01_000001_create_partner_teams.php'))->up();
        (require database_path('migrations/2026_11_01_000003_create_partner_notifications.php'))->up();
        Schema::create('bundles', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->decimal('price_usd',12,2); $t->boolean('active'); $t->integer('sim_bundle_id')->nullable();
            $t->integer('network_id')->nullable(); $t->integer('data_mb')->nullable(); $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::create('esims', function (Blueprint $t) {
            $t->id(); $t->string('msisdn')->unique(); $t->string('iccid')->nullable(); $t->integer('network_id');
            $t->string('sim_type'); $t->string('status'); $t->string('sale_status')->nullable(); $t->string('provider_status');
            $t->text('qr_code_data')->nullable(); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->string('draft_id')->unique(); $t->string('status'); $t->string('payment_status');
            foreach (['subtotal','discount_amount','total_amount'] as $f) $t->decimal($f,12,2);
            foreach (['currency','source','platform','payment_gateway','payment_reference','recharge_status','gateway_payment_id'] as $f) $t->string($f)->nullable();
            $t->json('metadata')->nullable(); $t->json('payment_payload')->nullable(); $t->json('payment_callback')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamp('recharge_completed_at')->nullable(); $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id(); $t->integer('order_id'); $t->integer('bundle_id'); $t->string('type'); $t->string('bundle_name');
            $t->integer('data_amount')->nullable(); $t->integer('validity_days'); $t->decimal('price',12,2); $t->string('currency'); $t->timestamps();
        });
        Schema::create('trips', function (Blueprint $t) {
            $t->id(); $t->integer('order_id'); $t->string('destination_country'); $t->date('arrival_date'); $t->date('departure_date'); $t->integer('duration_days'); $t->timestamps();
        });
        Schema::create('user_esims', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->integer('esim_id')->unique(); $t->integer('bundle_id')->nullable(); $t->integer('order_id')->nullable(); $t->integer('order_item_id')->nullable();
            $t->timestamp('device_activated_at')->nullable(); $t->timestamp('physical_issued_at')->nullable(); $t->timestamps();
        });
        Schema::create('kycs', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->text('passport_id_encrypted')->nullable(); $t->string('passport_country')->nullable(); });
        (require database_path('migrations/2026_10_31_000003_create_partner_bulk_orders.php'))->up();
        $this->bundle = Bundle::create(['name' => 'Tanzania 10GB', 'price_usd' => '20.00', 'active' => true, 'sim_bundle_id' => 42, 'network_id' => 1, 'data_mb' => 10240]);
    }

    public function test_only_approved_business_sessions_can_use_catalog_orders_and_stock(): void
    {
        foreach (['bulk/catalog','bulk','stock'] as $path) $this->getJson('/api/partner-auth/'.$path)->assertUnauthorized();
        foreach ([['hotel','under_review'],['content_creator','approved'],['tour_guide','approved'],['hotel','suspended']] as [$type,$status]) {
            $this->asPartner($this->partner($type,$status));
            foreach (['bulk/catalog','bulk','stock'] as $path) $this->getJson('/api/partner-auth/'.$path)->assertForbidden();
            $this->postJson('/api/partner-auth/bulk',$this->payload())->assertForbidden();
        }
    }

    public function test_catalog_uses_supported_real_prices_and_hides_private_free_and_inactive_skus(): void
    {
        $this->bundle->update(['price_usd' => '19.95']);
        foreach ([['metadata'=>['admin_only'=>true]],['price_usd'=>'0.00'],['active'=>false],['sim_bundle_id'=>null]] as $patch) Bundle::create([...$this->bundle->getAttributes(), 'id'=>null, ...$patch]);
        $this->asPartner($this->partner());
        $this->getJson('/api/partner-auth/bulk/catalog')->assertOk()->assertJsonCount(1,'bundles')->assertJsonPath('bundles.0.unit','18.95')->assertJsonPath('discountBps',500);
        $this->postJson('/api/partner-auth/bulk',$this->payload('189.50'))->assertOk()->assertJsonPath('order.total','189.50');
    }

    public function test_server_pricing_owner_and_status_cannot_be_forged_and_retries_are_idempotent(): void
    {
        $p = $this->partner(); $this->asPartner($p); $payload = [...$this->payload(), 'unit_cents'=>1,'total_cents'=>1,'status'=>'fulfilled','partner_application_id'=>999];
        $reference = $this->postJson('/api/partner-auth/bulk',$payload)->assertOk()->assertJsonPath('order.total','190.00')->assertJsonPath('order.status','pending_payment')->json('order.reference');
        $this->postJson('/api/partner-auth/bulk',$payload)->assertOk()->assertJsonPath('order.reference',$reference);
        $this->assertSame($p->id,PartnerBulkOrder::sole()->partner_application_id); $this->assertDatabaseCount('partner_bulk_orders',1); $this->assertDatabaseCount('partner_stock_units',0);
        foreach ([['quantity'=>9],['quantity'=>501],['quantity'=>10.5],['sim_type'=>'invalid'],['expected_total'=>'1.00'],['purchase_confirmed'=>false],['bundle_id'=>999]] as $patch) $this->postJson('/api/partner-auth/bulk',[...$this->payload(),...$patch])->assertUnprocessable();
    }

    public function test_negotiated_rates_must_be_configured_and_original_order_prices_are_preserved(): void
    {
        $this->asPartner($this->partner('airline'));
        $this->getJson('/api/partner-auth/bulk/catalog')->assertJsonPath('discountBps',null);
        $this->postJson('/api/partner-auth/bulk',$this->payload())->assertUnprocessable();
        $order = $this->bulk($this->partner());
        config(['partners.bulk_discount_bps.hotel'=>1000]); $this->bundle->update(['price_usd'=>'99.00']);
        $this->assertSame(19000,$order->fresh()->total_cents); $this->assertSame(1900,$order->fresh()->unit_cents);
    }

    public function test_admin_endpoints_reject_partner_tokens_and_non_admin_accounts(): void
    {
        $order=$this->bulk($this->partner());
        $this->getJson('/api/admin/partner-bulk')->assertUnauthorized(); $this->getJson('/api/admin/partner-bulk/'.$order->id)->assertUnauthorized();
        $this->review($order,'paid',0,$this->payment())->assertUnauthorized();
        foreach (['agent','user'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role'=>$role]));
            $this->getJson('/api/admin/partner-bulk')->assertForbidden(); $this->review($order,'paid',0,$this->payment())->assertForbidden();
        }
    }

    public function test_online_checkout_uses_server_total_and_gateway_verified_payment_only(): void
    {
        $p=$this->partner(); $order=$this->bulk($p); $this->asPartner($p);
        $this->postJson('/api/partner-auth/bulk/'.$order->id.'/checkout',['total_amount'=>1,'status'=>'paid'])->assertOk()->assertJsonPath('checkoutUrl','https://pay.example.test/checkout');
        $payment=Order::findOrFail($order->fresh()->payment_order_id);
        $this->assertSame('190.00',$payment->total_amount); $this->assertSame('pending_payment',$order->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(),'/api/v1/payment/card') && $request['details']['amount']==190 && str_starts_with($request['details']['redirectUrl'],'https://partners.example.test/dashboard/bulk?'));
        $this->postJson('/api/payments/evpay/callback',['event'=>'payment.succeeded'])->assertForbidden();
        $this->webhook($payment,1)->assertUnprocessable(); $this->assertSame('pending_payment',$order->fresh()->status);
        $this->webhook($payment,190)->assertOk(); $this->assertSame('paid',$order->fresh()->status);
        $this->webhook($payment,190)->assertOk();
        $this->assertSame(1,DB::table('partner_bulk_events')->where('action','paid')->count());
        $this->assertDatabaseCount('partner_stock_units',0); $this->assertDatabaseCount('user_esims',0);
        $this->admin(); $this->review($order,'paid',1,$this->payment())->assertUnprocessable();
    }

    public function test_checkout_and_refresh_are_scoped_and_started_checkouts_cannot_be_cancelled(): void
    {
        $p=$this->partner(); $order=$this->bulk($p); $this->asPartner($this->partner());
        $this->postJson('/api/partner-auth/bulk/'.$order->id.'/checkout',[])->assertNotFound();
        $this->postJson('/api/partner-auth/bulk/'.$order->id.'/refresh-payment',[])->assertNotFound();
        $this->asPartner($p); $this->postJson('/api/partner-auth/bulk/'.$order->id.'/checkout',[])->assertOk();
        $this->postJson('/api/partner-auth/bulk/'.$order->id.'/checkout',[])->assertOk();
        $this->assertDatabaseCount('orders',1);
        $this->admin(); $this->review($order,'cancelled',0,['message'=>'Cancel','no_payment_confirmed'=>true])->assertConflict();
    }

    public function test_insufficient_stock_never_erases_a_received_payment_or_allocates_partial_stock(): void
    {
        $o=$this->bulk($this->partner()); $this->admin(); $this->inventory(9);
        $this->review($o,'fulfilled',0)->assertConflict();
        $this->pay($o)->assertOk();
        $this->review($o,'fulfilled',1)->assertConflict();
        $this->review($o,'approved',1)->assertOk();
        $this->review($o,'fulfilled',2)->assertUnprocessable();
        $this->assertSame('approved',$o->fresh()->status); $this->assertDatabaseCount('partner_stock_units',0); $this->assertSame(0,Esim::whereNotNull('partner_bulk_order_id')->count());
        $this->inventory(1); $this->review($o,'fulfilled',2)->assertOk()->assertJsonPath('order.status','fulfilled');
        $this->assertDatabaseCount('partner_stock_units',10); $this->assertSame(10,Esim::whereNotNull('partner_bulk_order_id')->count());
        $this->review($o,'fulfilled',3)->assertConflict();
    }

    public function test_allocation_matches_type_network_readiness_and_excludes_sold_and_reserved_inventory(): void
    {
        $o=$this->bulk($this->partner());
        foreach ([['sim_type'=>'physical'],['network_id'=>2],['provider_status'=>'pending'],['qr_code_data'=>null],['sale_status'=>'sold'],['status'=>'MANAGED']] as $patch) $this->inventory(1,$patch);
        $this->inventory(10); $this->admin(); $this->pay($o)->assertOk(); $this->review($o,'approved',1)->assertOk(); $this->review($o,'fulfilled',2)->assertOk();
        foreach (PartnerStockUnit::with('esim')->get() as $u) { $this->assertSame('esim',$u->esim->sim_type); $this->assertSame(1,$u->esim->network_id); }
        $this->assertSame(0,Esim::availableForAssignment()->where('sim_type','esim')->where('provider_status','active')->where('network_id',1)->whereNotNull('qr_code_data')->count());
    }

    public function test_retained_partner_stock_cannot_be_reassigned_by_the_retail_model(): void
    {
        $o=$this->fulfilled($this->partner()); $unit=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        UserEsim::create(['user_id'=>User::factory()->create()->id,'esim_id'=>$unit->esim_id]);
    }

    public function test_cancel_requires_reason_and_nonpayment_confirmation_and_paid_orders_are_final(): void
    {
        $o=$this->bulk($this->partner()); $this->admin();
        $this->review($o,'cancelled',0,[])->assertUnprocessable();
        $this->review($o,'cancelled',0,['message'=>'PRIVATE-REASON','no_payment_confirmed'=>true])->assertOk();
        $this->assertStringNotContainsString('PRIVATE-REASON',DB::table('partner_bulk_events')->where('action','cancelled')->value('message'));
        $this->review($o,'paid',1,$this->payment())->assertUnprocessable();
    }

    public function test_assignment_creates_a_real_prepaid_customer_order_and_keeps_guest_details_encrypted(): void
    {
        $p=$this->partner(); $o=$this->fulfilled($p); $u=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first(); $guest=$this->guest(); $this->asPartner($p);
        $this->assign($u,$guest)->assertOk()->assertJsonPath('unit.state','assigned')->assertJsonPath('unit.version',1)->assertJsonPath('unit.bundleReady',false);
        $this->assertSame($guest->id,$u->fresh()->user_id); $this->assertSame(1,UserEsim::where('user_id',$guest->id)->count());
        $order=Order::findOrFail($u->fresh()->order_id); $this->assertSame('partner_prepaid',$order->payment_gateway); $this->assertSame('0.00',$order->total_amount); $this->assertSame(1900,$order->metadata['prepaid_unit_cents']); $this->assertSame(190.0,(float)Order::sum('total_amount'));
        $this->assertSame(1,$order->metadata['pricing_version']); $this->assertSame(30,$order->orderItems->first()->validity_days);
        $this->assertStringNotContainsString($guest->email,DB::table('partner_stock_units')->where('id',$u->id)->value('guest'));
        $this->assign($u,$guest)->assertConflict(); $this->assertDatabaseCount('orders',2); Http::assertNotSent(fn ($request)=>str_contains($request->url(),'/api/recharge')); Mail::assertSent(\App\Mail\PartnerUpdateMail::class,3); Mail::assertSentCount(3);
    }

    public function test_assignment_rejects_unverified_missing_details_existing_sim_and_no_guest_confirmation(): void
    {
        $p=$this->partner(); $o=$this->fulfilled($p); $u=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first();
        $guest=$this->guest(); $guest->update(['email_verified_at'=>null]); $this->asPartner($p); $this->assign($u,$guest)->assertUnprocessable();
        $guest->update(['email_verified_at'=>now()]); DB::table('kycs')->where('user_id',$guest->id)->delete(); $this->assign($u,$guest)->assertUnprocessable();
        $good=$this->guest(); $this->assign($u,$good,['guest_confirmed'=>false])->assertUnprocessable();
        $this->assign($u,$good,['arrival'=>'2020-01-01'])->assertUnprocessable();
        $this->inventory(1,['sim_type'=>'physical']); $free=Esim::whereNull('partner_bulk_order_id')->first(); UserEsim::create(['user_id'=>$good->id,'esim_id'=>$free->id]);
        $this->assign($u,$good)->assertUnprocessable(); $this->assertDatabaseCount('orders',1);
    }

    public function test_stock_is_owner_scoped_and_handover_is_not_fake_installation(): void
    {
        $p=$this->partner(); $o=$this->fulfilled($p,'physical'); $u=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first(); $guest=$this->guest();
        $this->asPartner($this->partner()); $this->getJson('/api/partner-auth/stock')->assertJsonCount(0,'units'); $this->assign($u,$guest)->assertNotFound();
        $this->asPartner($p); $this->assign($u,$guest)->assertOk();
        $this->postJson('/api/partner-auth/stock/'.$u->id.'/handover',['expected_version'=>1,'handover_confirmed'=>true])->assertOk()->assertJsonPath('unit.state','handed_over')->assertJsonPath('unit.bundleReady',false);
        $this->assertNotNull(UserEsim::find($u->fresh()->user_esim_id)->physical_issued_at);
        $this->postJson('/api/partner-auth/stock/'.$u->id.'/handover',['expected_version'=>1,'handover_confirmed'=>true])->assertConflict();
        $this->postJson('/api/partner-auth/stock/'.$u->id.'/installed',[])->assertNotFound();
    }

    public function test_installation_and_expiry_are_derived_from_actual_customer_and_recharge_records(): void
    {
        $p=$this->partner(); $o=$this->fulfilled($p); $u=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first(); $this->asPartner($p); $this->assign($u,$this->guest())->assertOk();
        UserEsim::whereKey($u->fresh()->user_esim_id)->update(['device_activated_at'=>now()]);
        $this->getJson('/api/partner-auth/stock?filter=assigned')->assertJsonPath('units.0.state','installed');
        Order::whereKey($u->fresh()->order_id)->update(['recharge_status'=>'success','recharge_completed_at'=>now()->subDays(31)]);
        $this->getJson('/api/partner-auth/stock?filter=assigned')->assertJsonPath('units.0.state','expired')->assertJsonPath('units.0.bundleReady',true);
    }

    public function test_catalog_product_changes_cannot_silently_change_a_prepaid_bundle(): void
    {
        $p=$this->partner(); $o=$this->fulfilled($p); $u=PartnerStockUnit::where('partner_bulk_order_id',$o->id)->first();
        $this->bundle->update(['sim_bundle_id'=>99]); $this->asPartner($p); $this->assign($u,$this->guest())->assertUnprocessable(); $this->assertDatabaseCount('orders',1);
    }

    public function test_failed_audit_rolls_back_inventory_allocation(): void
    {
        $o=$this->bulk($this->partner()); $admin=$this->admin(); $this->inventory(10); $this->pay($o)->assertOk(); $this->review($o,'approved',1)->assertOk();
        Schema::drop('partner_bulk_events');
        try { app(PartnerBulkService::class)->review($o->id,$admin,['decision'=>'fulfilled','expected_version'=>2]); $this->fail('Expected audit failure'); }
        catch (\Illuminate\Database\QueryException) { $this->assertDatabaseCount('partner_stock_units',0); $this->assertSame(0,Esim::whereNotNull('partner_bulk_order_id')->count()); $this->assertSame('approved',$o->fresh()->status); }
    }

    public function test_gateway_reversal_blocks_new_allocation_and_guest_assignment(): void
    {
        $p=$this->partner(); $order=$this->bulk($p); $this->inventory(10); $this->admin(); $this->pay($order)->assertOk();
        $payment=Order::findOrFail($order->fresh()->payment_order_id);
        $payment->update(['payment_callback'=>['last'=>['event'=>'payment.refunded']]]);
        $this->review($order,'approved',1)->assertUnprocessable(); $this->assertDatabaseCount('partner_stock_units',0);
        $payment->update(['payment_callback'=>null]); $this->review($order,'approved',1)->assertOk(); $this->review($order,'fulfilled',2)->assertOk();
        $payment->update(['payment_callback'=>['deliveries'=>[['event'=>'payment.reversed']]]]);
        $unit=PartnerStockUnit::where('partner_bulk_order_id',$order->id)->first(); $this->asPartner($p);
        $this->assign($unit,$this->guest())->assertUnprocessable(); $this->assertDatabaseCount('user_esims',0);
        $this->getJson('/api/partner-auth/bulk')->assertJsonPath('orders.0.paymentIssue',true);
    }

    public function test_retail_physical_walk_in_and_admin_assignment_cannot_steal_reserved_stock(): void
    {
        $p=$this->partner(); $order=$this->fulfilled($p,'physical');
        $unit=PartnerStockUnit::where('partner_bulk_order_id',$order->id)->first(); $guest=$this->guest(); $agent=User::factory()->create(['role'=>'agent']);
        $this->assertSame(0,app(\App\Services\Esim\SimAssignmentService::class)->availableCount('physical'));
        try {
            app(\App\Services\Esim\WalkInPhysicalSimService::class)->assign(['esim_id'=>$unit->esim_id,'customer_name'=>'Test guest','customer_email'=>$guest->email],$agent);
            $this->fail('Reserved stock was assigned to a walk-in');
        } catch (\Illuminate\Validation\ValidationException) { $this->assertDatabaseCount('user_esims',0); }
        $this->admin();
        $this->postJson('/api/admin/user-esims',['user_id'=>$guest->id,'esim_id'=>$unit->esim_id])->assertUnprocessable();
        $this->assertDatabaseCount('user_esims',0); Mail::assertSent(\App\Mail\PartnerUpdateMail::class,3); Mail::assertSentCount(3);
    }

    public function test_bulk_billing_orders_do_not_replace_customer_bundle_orders(): void
    {
        $order=$this->bulk($this->partner()); $this->pay($order)->assertOk(); $payment=Order::findOrFail($order->fresh()->payment_order_id);
        $this->assertFalse(app(\App\Services\Esim\UserEsimOrderLinkService::class)->paidOrdersForUser($payment->user_id)->exists());
        $this->assertDatabaseCount('user_esims',0); $this->assertDatabaseCount('partner_stock_units',0);
        Http::assertNotSent(fn ($request)=>str_contains($request->url(),'/api/recharge'));
    }

    public function test_paid_purchase_waits_for_separate_admin_approval_before_stock_allocation(): void
    {
        $p=$this->partner(); $order=$this->bulk($p); $this->inventory(10); $this->pay($order)->assertOk();
        $this->assertSame('paid',$order->fresh()->status); $this->assertDatabaseCount('partner_stock_units',0);
        $this->review($order,'approved',1)->assertUnauthorized();
        $admin=$this->admin();
        $this->review($order,'fulfilled',1)->assertConflict();
        $this->review($order,'approved',1)->assertOk()->assertJsonPath('order.status','approved')->assertJsonPath('order.version',2);
        $this->assertDatabaseCount('partner_stock_units',0);
        $this->assertSame($admin->id,DB::table('partner_bulk_events')->where('action','approved')->value('actor_id'));
        $this->review($order,'approved',2)->assertConflict();
        $this->webhook(Order::findOrFail($order->fresh()->payment_order_id),190)->assertOk();
        $this->assertSame('approved',$order->fresh()->status); $this->assertSame(2,$order->fresh()->version);
        $this->review($order,'fulfilled',2)->assertOk(); $this->assertDatabaseCount('partner_stock_units',10);
    }

    public function test_paid_purchase_rejection_preserves_payment_and_requires_a_reason_and_acknowledgement(): void
    {
        $p=$this->partner(); $order=$this->bulk($p); $this->pay($order)->assertOk(); $this->admin();
        $this->review($order,'rejected',1,[])->assertUnprocessable();
        $this->review($order,'rejected',1,['message'=>'PRIVATE-DECLINE'])->assertUnprocessable();
        $this->review($order,'rejected',1,['message'=>'PRIVATE-DECLINE','rejection_acknowledged'=>true])->assertOk()->assertJsonPath('order.status','rejected');
        $payment=Order::findOrFail($order->fresh()->payment_order_id);
        $this->assertSame('paid',$payment->payment_status); $this->assertSame(19000,$order->fresh()->received_cents);
        $this->assertNotNull($order->fresh()->paid_at); $this->assertDatabaseCount('partner_stock_units',0);
        $this->review($order,'approved',2)->assertConflict(); $this->review($order,'fulfilled',2)->assertConflict();
        $this->assertStringNotContainsString('PRIVATE-DECLINE',DB::table('partner_bulk_events')->where('action','rejected')->value('message'));
        $this->asPartner($p); $this->getJson('/api/partner-auth/bulk')->assertJsonPath('orders.0.status','rejected')->assertJsonPath('orders.0.message','PRIVATE-DECLINE');
    }

    public function test_approval_audit_failure_does_not_release_stock_or_approve_the_purchase(): void
    {
        $order=$this->bulk($this->partner()); $this->pay($order)->assertOk(); $admin=$this->admin();
        Schema::drop('partner_bulk_events');
        try { app(PartnerBulkService::class)->review($order->id,$admin,['decision'=>'approved','expected_version'=>1]); $this->fail('Expected audit failure'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame('paid',$order->fresh()->status); $this->assertSame(1,$order->fresh()->version); $this->assertDatabaseCount('partner_stock_units',0); }
    }

    public function test_front_desk_can_assign_and_handover_only_its_own_stock_with_staff_audit(): void
    {
        $p=$this->partner();$order=$this->fulfilled($p);$guest=$this->guest();
        $hash=\App\Support\PartnerIdentity::emailHash('desk@example.test');
        $member=\App\Models\PartnerTeamMember::create(['partner_application_id'=>$p->id,'request_key'=>(string)Str::uuid(),'email_hash'=>$hash,'active_email_hash'=>$hash,
            'details'=>['name'=>'Desk','email'=>'desk@example.test'],'role'=>'front_desk','status'=>'active']);
        $token=Str::random(64);DB::table('partner_sessions')->insert(['partner_application_id'=>$p->id,'partner_team_member_id'=>$member->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);
        $this->withToken($token)->getJson('/api/partner-auth/stock')->assertOk();
        $unit=PartnerStockUnit::where('partner_bulk_order_id',$order->id)->firstOrFail();
        $this->assign($unit,$guest,['team_actor'=>999])->assertOk();
        $this->postJson('/api/partner-auth/stock/'.$unit->id.'/handover',['expected_version'=>1,'handover_confirmed'=>true])->assertOk();
        foreach(['assigned','handed_over'] as $action){$event=DB::table('partner_bulk_events')->where('action',$action)->sole();$this->assertSame('staff',$event->actor_type);$this->assertSame($member->id,$event->actor_id);}
        $this->postJson('/api/partner-auth/bulk',$this->payload())->assertForbidden();
    }

    public function test_paid_approved_and_fulfilled_updates_are_recorded_by_real_workflow(): void
    {
        $p=$this->partner();$order=$this->fulfilled($p);
        $rows=DB::table('partner_notifications')->where('partner_application_id',$p->id)->orderBy('id')->get();
        $this->assertSame(['bulk','bulk','stock'],$rows->pluck('category')->all());
        $this->assertSame(3,$rows->pluck('event_key')->unique()->count());
        $this->asPartner($p);$this->getJson('/api/partner-auth/notifications')->assertOk()->assertJsonPath('unread',3);
    }

    private function partner(string $type='hotel',string $status='approved'): PartnerApplication
    {
        return PartnerApplication::create(['submission_key'=>(string)Str::uuid(),'reference'=>'TP-'.Str::uuid(),'status'=>$status,'application_payload'=>['type'=>$type,'profile'=>['displayName'=>'Test hotel','email'=>'bulk-'.Str::uuid().'@example.test']],'documents'=>[],'submitted_at'=>now()]);
    }
    private function asPartner(PartnerApplication $p): void
    {
        $token=Str::random(64); DB::table('partner_sessions')->insert(['partner_application_id'=>$p->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]); $this->withToken($token);
    }
    private function payload(string $total='190.00',string $type='esim'): array { return ['request_key'=>(string)Str::uuid(),'bundle_id'=>$this->bundle->id,'quantity'=>10,'sim_type'=>$type,'expected_total'=>$total,'purchase_confirmed'=>true]; }
    private function pay(PartnerBulkOrder $o)
    {
        $this->asPartner(PartnerApplication::findOrFail($o->partner_application_id));
        $this->postJson('/api/partner-auth/bulk/'.$o->id.'/checkout',[])->assertOk();
        return $this->webhook(Order::findOrFail($o->fresh()->payment_order_id),190);
    }
    private function webhook(Order $payment,int $amount)
    {
        $t=(string)time();
        $body=json_encode(['event'=>'payment.succeeded','timestamp'=>(int)$t,'data'=>['id'=>$payment->gateway_payment_id,'orderReference'=>$payment->payment_reference,'amount'=>$amount,'currency'=>'USD','status'=>'SUCCESS']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $signature=hash_hmac('sha256',$t.'.'.$body,config('services.evpay.signing_key'));
        return $this->call('POST','/api/payments/evpay/callback',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_EVPAY_SIGNATURE'=>'t='.$t.',v1='.$signature,'HTTP_X_EVPAY_DELIVERY'=>(string)Str::uuid()],$body);
    }
    private function payment(): array { return ['payment_reference'=>'RECEIPT-001','received_currency'=>'USD','received_amount'=>'190.00','payment_confirmed'=>true]; }
    private function bulk(PartnerApplication $p,string $type='esim'): PartnerBulkOrder
    {
        $this->asPartner($p); $id=$this->postJson('/api/partner-auth/bulk',$this->payload('190.00',$type))->assertOk()->json('order.id'); return PartnerBulkOrder::findOrFail($id);
    }
    private function admin(): User { $a=User::factory()->create(['role'=>'admin']); Sanctum::actingAs($a); return $a; }
    private function review(PartnerBulkOrder $o,string $decision,int $version,array $data=[]) { return $this->postJson('/api/admin/partner-bulk/'.$o->id.'/review',['decision'=>$decision,'expected_version'=>$version,...$data]); }
    private function inventory(int $count,array $patch=[]): void
    {
        for($i=0;$i<$count;$i++) Esim::create(['msisdn'=>Str::random(18),'iccid'=>Str::random(20),'network_id'=>1,'sim_type'=>'esim','status'=>'AVAILABLE','sale_status'=>'available','provider_status'=>'active','qr_code_data'=>'LPA:TEST',...$patch]);
    }
    private function fulfilled(PartnerApplication $p,string $type='esim'): PartnerBulkOrder
    {
        $o=$this->bulk($p,$type); $this->inventory(10,['sim_type'=>$type]); $this->admin();
        $this->pay($o)->assertOk(); $this->review($o,'approved',1)->assertOk(); $this->review($o,'fulfilled',2)->assertOk(); return $o->fresh();
    }
    private function guest(): User
    {
        $u=User::factory()->create(['role'=>'user','email_verified_at'=>now()]); DB::table('kycs')->insert(['user_id'=>$u->id,'passport_id_encrypted'=>'ENCRYPTED_TEST','passport_country'=>'TZ']); return $u;
    }
    private function assign(PartnerStockUnit $u,User $guest,array $patch=[])
    {
        return $this->postJson('/api/partner-auth/stock/'.$u->id.'/assign',['expected_version'=>0,'name'=>'Synthetic traveller','email'=>$guest->email,'arrival'=>now('Africa/Dar_es_Salaam')->addDays(2)->toDateString(),'country'=>'TZ','guest_confirmed'=>true,...$patch]);
    }
}
