<?php

namespace Tests\Feature;

use App\Mail\EsimActivationQrMail;
use App\Mail\EsimRechargeConfirmationMail;
use App\Models\{Bundle, Esim, Order, OrderItem, Trip, User, UserEsim};
use App\Services\Esim\EsimActivationEmailService;
use App\Services\Esim\OrderRechargeService;
use App\Services\Esim\OrderEmailPreparationService;
use App\Services\Esim\VodacomBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Http, Mail, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderEmailTest extends TestCase
{
    private User $admin;
    private Order $order;
    private UserEsim $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['cache.default' => 'array', 'services.resend.key' => 'test-key']);
        Http::preventStrayRequests();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('role'); $t->string('password')->nullable(); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->string('recharge_reference')->nullable(); $t->string('recharge_transaction_id')->nullable();
            $t->json('recharge_response')->nullable(); $t->unsignedInteger('recharge_http_status')->nullable(); $t->timestamp('recharge_completed_at')->nullable();
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('draft_id')->nullable();
            $t->string('status')->nullable(); $t->string('payment_status')->nullable(); $t->json('metadata')->nullable();
            $t->string('payment_reference')->nullable(); $t->string('recharge_status')->nullable(); $t->timestamps();
        });
        Schema::create('esims', function (Blueprint $t) {
            $t->string('provider_status')->nullable(); $t->unsignedBigInteger('partner_bulk_order_id')->nullable();
            $t->unsignedInteger('network_id')->nullable(); $t->string('status')->nullable();
            $t->id(); $t->string('sim_type'); $t->string('msisdn')->nullable(); $t->string('iccid')->nullable(); $t->text('qr_code_data')->nullable(); $t->timestamps();
        });
        Schema::create('user_esims', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('esim_id');
            $t->unsignedBigInteger('bundle_id')->nullable(); $t->unsignedBigInteger('order_item_id')->nullable();
            $t->timestamp('activation_email_sent_at')->nullable(); $t->timestamps();
        });
        Schema::create('bundles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->unsignedInteger('sim_bundle_id')->nullable(); $t->timestamps(); });
        Schema::create('order_items', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('bundle_id'); $t->string('type'); $t->json('metadata')->nullable(); $t->timestamps(); });
        Schema::create('trips', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->date('arrival_date'); $t->timestamps(); });
        (require database_path('migrations/2026_11_01_000004_create_esim_email_attempts.php'))->up();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin']);
        $user = User::create(['name' => 'Traveller', 'email' => 'traveller@example.test', 'role' => 'user']);
        $this->order = Order::create(['user_id' => $user->id, 'draft_id' => 'EMAIL-1', 'status' => 'paid', 'payment_status' => 'paid', 'metadata' => ['simType' => 'esim'], 'recharge_status' => 'success']);
        $esim = Esim::create(['sim_type' => 'esim', 'provider_status' => Esim::PROVIDER_STATUS_ACTIVE, 'network_id' => 1, 'iccid' => '12345', 'msisdn' => '255700000001', 'qr_code_data' => 'LPA:1$smdp.example.test$QR']);
        $this->assignment = UserEsim::create(['user_id' => $user->id, 'order_id' => $this->order->id, 'esim_id' => $esim->id, 'activation_email_sent_at' => now()->subDay()]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/admin/orders/'.$this->order->id.'/email'.$suffix;
    }

    private function mockTransport(bool $includeQr = true, bool $confirmation = false): void
    {
        $email = (new \Symfony\Component\Mime\Email)->from('sender@example.test')->to('traveller@example.test')->text('QR');
        $email->getHeaders()->addTextHeader('X-Resend-Email-ID', '11111111-2222-3333-4444-555555555555');
        $sent = new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, \Symfony\Component\Mailer\Envelope::create($email)));
        Mail::shouldReceive('to')->once()->with('traveller@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->with(\Mockery::on(fn ($mail) => $mail instanceof EsimActivationQrMail && $mail->iccid === '12345'
            && $mail->qrCodeData === ($includeQr ? 'LPA:1$smdp.example.test$QR' : null)
            && (! $confirmation || ($mail instanceof EsimRechargeConfirmationMail && $mail->bundleName === 'Nomad'))))->andReturn($sent);
    }

    public function test_admin_can_resend_and_capture_provider_id_and_check_delivery(): void
    {
        Sanctum::actingAs($this->admin);
        $this->mockTransport();
        $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertOk();
        $this->assertDatabaseHas('esim_email_attempts', ['requested_by' => $this->admin->id, 'recipient' => 'traveller@example.test', 'provider_id' => '11111111-2222-3333-4444-555555555555', 'status' => 'sent']);
        Http::fake(['api.resend.com/emails/*' => Http::response(['last_event' => 'delivered'])]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.attempts.0.status', 'delivered');
        $this->getJson($this->url())->assertOk();
        Http::assertSentCount(1);
        $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertStatus(429);
    }

    public function test_authentication_and_admin_role_are_required(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertUnauthorized();
        Sanctum::actingAs($this->assignment->user);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertForbidden();
    }

    public function test_ineligible_or_foreign_assignments_cannot_be_sent(): void
    {
        Mail::fake(); Sanctum::actingAs($this->admin);
        $payload = ['assignment_id' => $this->assignment->id];
        $this->order->update(['recharge_status' => 'pending']);
        $this->postJson($this->url('/resend'), $payload)->assertUnprocessable();
        $this->order->update(['recharge_status' => 'success']);
        $this->assignment->esim->update(['sim_type' => 'physical']);
        $this->postJson($this->url('/resend'), $payload)->assertUnprocessable();
        $this->assignment->esim->update(['sim_type' => 'esim', 'qr_code_data' => null]);
        $this->postJson($this->url('/resend'), $payload)->assertUnprocessable();
        $this->assignment->update(['order_id' => $this->order->id + 1]);
        $this->postJson($this->url('/resend'), $payload)->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_failure_is_recorded_and_provider_errors_do_not_claim_delivery(): void
    {
        Sanctum::actingAs($this->admin);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertStatus(502);
        $this->assertDatabaseHas('esim_email_attempts', ['status' => 'failed']);
        DB::table('esim_email_attempts')->update(['provider_id' => 'provider-id', 'status' => 'sent']);
        Http::fake(['api.resend.com/*' => Http::response([], 403)]);
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.attempts.0.status', 'sent');
        $this->assertNotNull($response->json('data.warning'));
    }

    public function test_automatic_send_remains_once_only_and_shared_lock_blocks_resends(): void
    {
        Mail::fake();
        $this->assignment->update(['activation_email_sent_at' => null]);
        $service = app(EsimActivationEmailService::class);
        $this->assertTrue($service->sendIfEligible($this->assignment));
        $this->assertFalse($service->sendIfEligible($this->assignment));
        Mail::assertSentCount(1);
        Sanctum::actingAs($this->admin);
        $lock = Cache::lock('esim-activation-email:'.$this->assignment->id, 120); $lock->get();
        try { $this->postJson($this->url('/resend'), ['assignment_id' => $this->assignment->id])->assertStatus(409); }
        finally { $lock->release(); }
    }

    private function unassignedPaidOrder(): void
    {
        $this->assignment->delete();
        $this->order->update(['recharge_status' => null]);
        $bundle = Bundle::create(['name' => 'Nomad', 'sim_bundle_id' => 66]);
        OrderItem::create(['order_id' => $this->order->id, 'bundle_id' => $bundle->id, 'type' => 'bundle']);
        Sanctum::actingAs($this->admin);
    }

    public function test_prepare_assigns_real_inventory_recharges_then_sends_once(): void
    {
        $this->unassignedPaidOrder();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.can_prepare', true);
        $this->mockTransport();
        $this->mock(OrderRechargeService::class, function ($mock) {
            $mock->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function ($order) {
                $this->assertTrue(UserEsim::where('order_id', $order->id)->exists());
                $order->update(['recharge_status' => 'success']);
                return ['processed' => 1];
            });
        });
        $this->postJson($this->url('/prepare'))->assertOk()->assertJsonPath('data.email_sent', true);
        $this->assertSame(1, UserEsim::where('order_id', $this->order->id)->count());
        $this->assertSame($this->admin->id, data_get($this->order->fresh()->metadata, 'email_preparation.requested_by'));
        $this->assertDatabaseHas('esim_email_attempts', ['requested_by' => $this->admin->id, 'status' => 'sent']);
        $this->postJson($this->url('/prepare'))->assertOk()->assertJsonPath('data.email_sent', true);
        $this->assertSame(1, DB::table('esim_email_attempts')->count());
    }

    public function test_prepare_blocks_unpaid_scheduled_topup_and_physical_orders(): void
    {
        $this->unassignedPaidOrder(); Mail::fake();
        $this->mock(OrderRechargeService::class, fn ($mock) => $mock->shouldNotReceive('rechargePaidOrder'));
        $this->order->update(['status' => 'pending', 'payment_status' => 'pending']);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
        $this->getJson($this->url())->assertJsonPath('data.can_prepare', false)->assertJsonPath('data.prepare_reason', 'Payment must be confirmed before recharging the bundle.');
        $this->order->update(['status' => 'paid', 'payment_status' => 'paid']);
        $trip = Trip::create(['order_id' => $this->order->id, 'arrival_date' => now()->addWeek()->toDateString()]);
        $this->postJson($this->url('/prepare'))->assertUnprocessable(); $trip->delete();
        $this->order->update(['metadata' => ['simType' => 'esim', 'checkoutMode' => 'topup']]);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
        $this->order->update(['metadata' => ['simType' => 'physical']]);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
        $this->assertSame(0, UserEsim::count()); Mail::assertNothingSent();
    }

    public function test_prepare_waits_for_carrier_confirmation_without_sending_email(): void
    {
        $this->unassignedPaidOrder(); Mail::fake();
        $this->mock(OrderRechargeService::class, function ($mock) {
            $mock->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function ($order) {
                $order->update(['recharge_status' => 'queued']); return ['processed' => 1];
            });
        });
        $this->postJson($this->url('/prepare'))->assertOk()->assertJsonPath('data.email_sent', false);
        $this->getJson($this->url())->assertJsonPath('data.assignments.0.msisdn', '255700000001')->assertJsonPath('data.assignments.0.can_resend', false);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
        $this->assertSame(1, UserEsim::count()); Mail::assertNothingSent();
    }

    public function test_prepare_does_not_allocate_reserved_or_incomplete_stock(): void
    {
        $this->unassignedPaidOrder(); Mail::fake();
        $this->mock(OrderRechargeService::class, fn ($mock) => $mock->shouldNotReceive('rechargePaidOrder'));
        DB::table('esims')->update(['partner_bulk_order_id' => 1]);
        $this->postJson($this->url('/prepare'))->assertStatus(409);
        DB::table('esims')->update(['partner_bulk_order_id' => null, 'qr_code_data' => '']);
        $this->postJson($this->url('/prepare'))->assertStatus(409);
        $this->assertSame(0, UserEsim::count()); Mail::assertNothingSent();
    }

    public function test_prepare_requires_admin_and_rejects_other_order_customer_sim(): void
    {
        $this->unassignedPaidOrder();
        Sanctum::actingAs($this->order->user);
        $this->postJson($this->url('/prepare'))->assertForbidden();
        Sanctum::actingAs($this->admin);
        UserEsim::create(['user_id' => $this->order->user_id, 'order_id' => $this->order->id + 1, 'esim_id' => $this->assignment->esim_id]);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
    }

    private function assignedOrderWithBundle(?string $rechargeStatus = null): void
    {
        $this->order->update(['recharge_status' => $rechargeStatus]);
        $bundle = Bundle::create(['name' => 'Nomad', 'sim_bundle_id' => 66]);
        OrderItem::create(['order_id' => $this->order->id, 'bundle_id' => $bundle->id, 'type' => 'bundle']);
        Sanctum::actingAs($this->admin);
    }

    public function test_existing_number_is_recharged_and_notified_without_qr_or_new_assignment(): void
    {
        $this->assignedOrderWithBundle();
        $this->assignment->esim->update(['qr_code_data' => null]);
        $this->getJson($this->url())->assertJsonPath('data.can_prepare', true)->assertJsonPath('data.prepare_label', 'Recharge bundle & email');
        $this->mockTransport(includeQr: false, confirmation: true);
        $this->mock(OrderRechargeService::class, function ($mock) {
            $mock->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function ($order) {
                $this->assertSame($this->assignment->id, data_get($order->metadata, 'user_esim_id'));
                $this->assertSame('255700000001', data_get($order->metadata, 'msisdn'));
                $order->update(['recharge_status' => 'success']); return ['processed' => 1];
            });
        });
        $this->postJson($this->url('/prepare'))->assertOk()->assertJsonPath('data.email_sent', true);
        $this->postJson($this->url('/prepare'))->assertOk();
        $this->assertSame(1, UserEsim::count());
        $this->assertSame(1, DB::table('esim_email_attempts')->count());
    }

    public function test_successful_recharge_sends_confirmation_without_loading_bundle_again(): void
    {
        $this->assignedOrderWithBundle('success');
        $this->mockTransport(includeQr: false, confirmation: true);
        $this->mock(OrderRechargeService::class, fn ($mock) => $mock->shouldNotReceive('rechargePaidOrder'));
        $this->getJson($this->url())->assertJsonPath('data.prepare_label', 'Email recharge confirmation');
        $this->postJson($this->url('/prepare'))->assertOk();
        $this->postJson($this->url('/prepare'))->assertOk();
        $this->assertSame(1, UserEsim::count());
    }

    public function test_confirmed_failure_can_retry_but_uncertain_carrier_result_cannot(): void
    {
        $this->assignedOrderWithBundle('failed'); Mail::fake();
        $this->mock(OrderRechargeService::class, function ($mock) {
            $mock->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function ($order) {
                $order->update(['recharge_status' => 'success']); return ['processed' => 1];
            });
        });
        $item = $this->order->orderItems()->first();
        $item->update(['metadata' => ['recharge' => ['status' => 'pending_retry']]]);
        $this->postJson($this->url('/prepare'))->assertUnprocessable();
        $item->update(['metadata' => ['recharge' => ['status' => 'failed']]]);
        $this->postJson($this->url('/prepare'))->assertOk();
        Mail::assertSent(EsimRechargeConfirmationMail::class, 1);
    }

    public function test_queued_recharge_callback_sends_confirmation_once(): void
    {
        $this->assignedOrderWithBundle(); Mail::fake();
        $this->mock(OrderRechargeService::class, function ($mock) {
            $mock->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function ($order) {
                $order->update(['recharge_status' => 'queued', 'recharge_reference' => 'RECHARGE-EMAIL']); return ['processed' => 1];
            });
        });
        $this->postJson($this->url('/prepare'))->assertOk()->assertJsonPath('data.email_sent', false);
        Mail::assertNothingSent();
        $this->app->forgetInstance(OrderRechargeService::class);
        $this->mock(VodacomBalanceService::class, function ($mock) {
            $mock->shouldReceive('requestBalancesForMsisdn')->andReturn(['status' => 'success']);
        });
        $callback = ['reference' => 'RECHARGE-EMAIL', 'status' => 'SUCCESS', 'transaction_id' => 'carrier-email-1'];
        $service = app(OrderRechargeService::class);
        $this->assertSame('success', $service->applyRechargeCallback($callback)['recharge_status']);
        $service->applyRechargeCallback($callback);
        Mail::assertSent(EsimRechargeConfirmationMail::class, 1);
        $this->assertSame('sent', data_get($this->order->fresh()->metadata, 'email_preparation.notification_status'));
    }

    public function test_email_failure_retries_only_notification_after_successful_recharge(): void
    {
        $this->assignedOrderWithBundle('success');
        $this->mock(OrderRechargeService::class, fn ($mock) => $mock->shouldNotReceive('rechargePaidOrder'));
        $manager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Email provider unavailable'));
        $this->postJson($this->url('/prepare'))->assertStatus(502);
        $this->assertSame('success', $this->order->fresh()->recharge_status);
        $this->travel(61)->seconds(); Mail::swap($manager); Mail::fake();
        $this->postJson($this->url('/prepare'))->assertOk();
        Mail::assertSent(EsimRechargeConfirmationMail::class, 1);
        $this->assertSame(1, UserEsim::count());
    }

    public function test_confirmation_contains_recharge_details_and_existing_number_has_no_attachment(): void
    {
        $mail = new EsimRechargeConfirmationMail('Traveller', '255700000001', null, 'EMAIL-1', 'Nomad');
        $html = $mail->content()->htmlString;
        $this->assertStringContainsString('successfully added', $html);
        $this->assertStringContainsString('255700000001', $html);
        $this->assertStringContainsString('Nomad', $html);
        $this->assertSame([], $mail->attachments());
    }
}
