<?php

namespace Tests\Unit;

use App\Mail\EsimActivationQrMail;
use App\Models\Order;
use App\Models\UserEsim;
use App\Models\User;
use App\Models\Esim;
use App\Services\Esim\EsimActivationEmailService;
use App\Services\Esim\OrderRechargeService;
use App\Services\Esim\ScheduledEsimActivationService;
use App\Services\Esim\SimAssignmentService;
use Mockery;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduledEsimActivationTest extends TestCase
{
    public function test_assignment_then_recharge_then_email_in_that_order(): void
    {
        [$service, $order, $assign, $recharge, $email] = $this->fixture();
        $assignment = new UserEsim;
        $assign->shouldReceive('assignForPaidOrder')->once()->ordered()->andReturn(['assigned' => true, 'assignment' => $assignment]);
        $recharge->shouldReceive('rechargePaidOrder')->once()->ordered()->andReturnUsing(function () use ($order) {
            $order->recharge_status = 'success';
            return ['recharge_status' => 'success'];
        });
        $email->shouldReceive('sendIfEligible')->with($assignment)->once()->ordered()->andReturn(true);
        $this->assertSame(['status' => 'ready', 'email_sent' => true], $service->activateOrder($order));
    }

    public function test_future_or_unpaid_orders_have_no_assignment_recharge_or_email(): void
    {
        foreach (['future', 'unpaid'] as $case) {
            [$service, $order, $assign, $recharge, $email] = $this->fixture($case !== 'unpaid', $case !== 'future');
            $assign->shouldNotReceive('assignForPaidOrder');
            $recharge->shouldNotReceive('rechargePaidOrder');
            $email->shouldNotReceive('sendIfEligible');
            $this->assertSame('not_due', $service->activateOrder($order)['status']);
        }
    }

    public function test_unavailable_inventory_does_not_recharge_or_email(): void
    {
        [$service, $order, $assign, $recharge, $email] = $this->fixture();
        $assign->shouldReceive('assignForPaidOrder')->once()->andReturn(['assigned' => false, 'reason' => 'no_esim_inventory']);
        $recharge->shouldNotReceive('rechargePaidOrder'); $email->shouldNotReceive('sendIfEligible');
        $this->assertSame('no_esim_inventory', $service->activateOrder($order)['status']);
    }

    public function test_failed_recharge_does_not_send_ready_email(): void
    {
        [$service, $order, $assign, $recharge, $email] = $this->fixture();
        $assign->shouldReceive('assignForPaidOrder')->andReturn(['assigned' => true, 'assignment' => new UserEsim]);
        $recharge->shouldReceive('rechargePaidOrder')->once()->andReturnUsing(function () use ($order) {
            $order->recharge_status = 'failed'; return ['recharge_status' => 'failed'];
        });
        $email->shouldNotReceive('sendIfEligible');
        $this->assertSame('failed', $service->activateOrder($order)['status']);
    }

    public function test_queued_recharge_is_not_submitted_again_and_email_waits_for_success(): void
    {
        [$service, $order, $assign, $recharge, $email] = $this->fixture();
        $order->recharge_status = 'queued';
        $assign->shouldReceive('assignForPaidOrder')->andReturn(['assigned' => true, 'assignment' => new UserEsim]);
        $recharge->shouldNotReceive('rechargePaidOrder');
        $email->shouldNotReceive('sendIfEligible');
        $this->assertSame('queued', $service->activateOrder($order)['status']);
    }

    public function test_retry_after_success_only_retries_email_without_recharging(): void
    {
        [$service, $order, $assign, $recharge, $email] = $this->fixture();
        $order->recharge_status = 'success';
        $assign->shouldReceive('assignForPaidOrder')->andReturn(['assigned' => true, 'assignment' => new UserEsim]);
        $recharge->shouldNotReceive('rechargePaidOrder');
        $email->shouldReceive('sendIfEligible')->twice()->andReturn(false, true);
        $this->assertFalse($service->activateOrder($order)['email_sent']);
        $this->assertTrue($service->activateOrder($order)['email_sent']);
    }

    public function test_installation_email_contains_a_qr_png_and_installation_instructions(): void
    {
        if (! extension_loaded('gd')) $this->markTestSkipped('PHP GD is required for QR PNG generation.');
        $mail = new EsimActivationQrMail('Test Customer', '255123456789', 'test-iccid', 'test-order',
            'https://thetravela.com/dashboard', 'LPA:1$smdp.example.com$TEST');
        $attachment = $mail->attachments()[0];
        $this->assertSame('travela-esim-qr.png', $attachment->as);
        $this->assertSame('image/png', $attachment->mime);
        $this->assertStringContainsString('All you need to do is install', $mail->content()->htmlString);
    }

    public function test_ready_email_waits_for_recharge_and_is_sent_once_with_the_customer_qr(): void
    {
        Mail::fake();
        $assignment = Mockery::mock(UserEsim::class)->makePartial();
        $assignment->shouldReceive('refresh')->andReturnSelf();
        $assignment->shouldReceive('loadMissing')->andReturnSelf();
        $assignment->shouldReceive('save')->once()->andReturn(true);
        $order = new Order(['draft_id' => 'TEST-ORDER', 'recharge_status' => 'pending']);
        $assignment->setRelation('order', $order);
        $assignment->setRelation('user', new User(['name' => 'Test Customer', 'email' => 'test@example.com']));
        $assignment->setRelation('esim', new Esim(['sim_type' => 'esim', 'msisdn' => '255123456789',
            'qr_code_data' => 'LPA:1$smdp.example.com$TEST']));
        $service = app(EsimActivationEmailService::class);
        $this->assertFalse($service->sendIfEligible($assignment));
        Mail::assertNothingSent();
        $order->recharge_status = 'success';
        $this->assertTrue($service->sendIfEligible($assignment));
        $this->assertFalse($service->sendIfEligible($assignment));
        Mail::assertSent(EsimActivationQrMail::class, fn ($mail) => $mail->hasTo('test@example.com')
            && $mail->qrCodeData === 'LPA:1$smdp.example.com$TEST');
        Mail::assertSentCount(1);
    }

    public function test_orders_without_a_customer_do_not_assign_recharge_or_email(): void
    {
        [$service, $order, $assignment, $recharge, $email] = $this->fixture();
        $order->user_id = null;
        $assignment->shouldNotReceive('assignForPaidOrder');
        $recharge->shouldNotReceive('rechargePaidOrder');
        $email->shouldNotReceive('sendIfEligible');
        $this->assertSame('missing_customer', $service->activateOrder($order)['status']);
    }

    private function fixture(bool $paid = true, bool $due = true): array
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->user_id = 1;
        $order->metadata = ['simType' => 'esim'];
        $order->shouldReceive('refresh')->andReturnSelf();
        $assignment = Mockery::mock(SimAssignmentService::class);
        $assignment->shouldReceive('orderIsPaid')->andReturn($paid);
        $assignment->shouldReceive('orderSimType')->andReturn('esim');
        $assignment->shouldReceive('activationDateIsDue')->andReturn($due);
        $recharge = Mockery::mock(OrderRechargeService::class);
        $email = Mockery::mock(EsimActivationEmailService::class);
        return [new ScheduledEsimActivationService($assignment, $recharge, $email), $order, $assignment, $recharge, $email];
    }
}
