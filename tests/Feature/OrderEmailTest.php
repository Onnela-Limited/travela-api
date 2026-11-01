<?php

namespace Tests\Feature;

use App\Mail\EsimActivationQrMail;
use App\Models\{Esim, Order, User, UserEsim};
use App\Services\Esim\EsimActivationEmailService;
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
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('draft_id')->nullable();
            $t->string('payment_reference')->nullable(); $t->string('recharge_status')->nullable(); $t->timestamps();
        });
        Schema::create('esims', function (Blueprint $t) {
            $t->id(); $t->string('sim_type'); $t->string('msisdn')->nullable(); $t->string('iccid')->nullable(); $t->text('qr_code_data')->nullable(); $t->timestamps();
        });
        Schema::create('user_esims', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('esim_id');
            $t->timestamp('activation_email_sent_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_11_01_000004_create_esim_email_attempts.php'))->up();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin']);
        $user = User::create(['name' => 'Traveller', 'email' => 'traveller@example.test', 'role' => 'user']);
        $this->order = Order::create(['user_id' => $user->id, 'draft_id' => 'EMAIL-1', 'recharge_status' => 'success']);
        $esim = Esim::create(['sim_type' => 'esim', 'iccid' => '12345', 'msisdn' => '255700000001', 'qr_code_data' => 'LPA:1$smdp.example.test$QR']);
        $this->assignment = UserEsim::create(['user_id' => $user->id, 'order_id' => $this->order->id, 'esim_id' => $esim->id, 'activation_email_sent_at' => now()->subDay()]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/admin/orders/'.$this->order->id.'/email'.$suffix;
    }

    private function mockTransport(): void
    {
        $email = (new \Symfony\Component\Mime\Email)->from('sender@example.test')->to('traveller@example.test')->text('QR');
        $email->getHeaders()->addTextHeader('X-Resend-Email-ID', '11111111-2222-3333-4444-555555555555');
        $sent = new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, \Symfony\Component\Mailer\Envelope::create($email)));
        Mail::shouldReceive('to')->once()->with('traveller@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->with(\Mockery::on(fn ($mail) => $mail instanceof EsimActivationQrMail && $mail->iccid === '12345' && $mail->qrCodeData === 'LPA:1$smdp.example.test$QR'))->andReturn($sent);
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
}
