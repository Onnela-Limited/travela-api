<?php

namespace Tests\Feature;

use App\Mail\EsimActivationQrMail;
use App\Models\Esim;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\EsimActivationEmailService;
use App\Services\Esim\QrCode\LpaQrCodeGenerator;
use App\Services\Esim\SimAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EsimActivationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_lpa_qr_generator_normalizes_payload_without_prefix(): void
    {
        $generator = new LpaQrCodeGenerator;

        $this->assertSame(
            'LPA:1$smdp.example.com$ACTIVATION-CODE',
            $generator->normalizeLpaPayload('smdp.example.com$ACTIVATION-CODE'),
        );
    }

    public function test_lpa_qr_generator_returns_png_data_uri(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required to generate QR images.');
        }

        $generator = new LpaQrCodeGenerator;
        $dataUri = $generator->pngDataUri('LPA:1$smdp.example.com$ACTIVATION-CODE');

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_activation_email_is_sent_once_after_successful_recharge(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'esim-customer@example.com',
            'name' => 'Jane Doe',
        ]);

        Esim::query()->create([
            'msisdn' => '255793045401',
            'iccid' => '8925500000000000101',
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'status' => 'AVAILABLE',
            'sale_status' => Esim::SALE_STATUS_AVAILABLE,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'network_id' => 1,
            'qr_code_data' => 'LPA:1$smdp.example.com$EMAIL-QR',
        ]);

        $order = Order::query()->create([
            'draft_id' => 'DRAFT-EMAIL-001',
            'user_id' => $user->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'total_amount' => '10.00',
            'currency' => 'USD',
            'metadata' => ['simType' => Esim::SIM_TYPE_ESIM],
            'paid_at' => now(),
        ]);

        $result = app(SimAssignmentService::class)->assignForPaidOrder($order);

        $this->assertTrue($result['assigned'] ?? false);
        $assignment = $result['assignment'];
        $this->assertInstanceOf(UserEsim::class, $assignment);
        $this->assertNull($assignment->fresh()->activation_email_sent_at);
        Mail::assertNothingSent();
        $order->update(['recharge_status' => 'success', 'recharge_completed_at' => now()]);
        $this->assertTrue(app(EsimActivationEmailService::class)->sendIfEligible($assignment));
        $this->assertNotNull($assignment->fresh()->activation_email_sent_at);

        Mail::assertSent(EsimActivationQrMail::class, function (EsimActivationQrMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->msisdn === '255793045401'
                && $mail->iccid === '8925500000000000101'
                && $mail->dashboardUrl === 'https://thetravela.com/dashboard';
        });

        Mail::assertSentCount(1);

        $sentAgain = app(EsimActivationEmailService::class)->sendIfEligible($assignment->fresh());
        $this->assertFalse($sentAgain);
        Mail::assertSentCount(1);
    }

    public function test_activation_email_is_not_sent_without_qr_code_data(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'no-qr@example.com']);

        $assignment = UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_id' => Esim::query()->create([
                'msisdn' => '255793045402',
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'status' => 'MANAGED',
                'sale_status' => Esim::SALE_STATUS_SOLD,
                'network_id' => 1,
                'qr_code_data' => null,
            ])->id,
        ]);

        $sent = app(EsimActivationEmailService::class)->sendIfEligible($assignment);

        $this->assertFalse($sent);
        $this->assertNull($assignment->fresh()->activation_email_sent_at);
        Mail::assertNothingSent();
    }

    public function test_assignment_payload_includes_activation_email_sent_at(): void
    {
        $user = User::factory()->create();
        $sentAt = now()->subMinutes(5);

        $assignment = UserEsim::query()->create([
            'user_id' => $user->id,
            'activation_email_sent_at' => $sentAt,
            'esim_id' => Esim::query()->create([
                'msisdn' => '255793045403',
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'status' => 'MANAGED',
                'sale_status' => Esim::SALE_STATUS_SOLD,
                'network_id' => 1,
                'qr_code_data' => 'LPA:1$smdp.example.com$ASSIGNMENT',
            ])->id,
        ]);

        $payload = $assignment->fresh()->toAssignmentArray();

        $this->assertArrayHasKey('activation_email_sent_at', $payload);
        $this->assertNotNull($payload['activation_email_sent_at']);
    }
}
