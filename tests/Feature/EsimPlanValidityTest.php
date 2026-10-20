<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\SimAssignmentService;
use App\Services\Esim\UserEsimOrderLinkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EsimPlanValidityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // The application's unrelated ENUM migration requires MySQL. Keep this
        // suite isolated to the tables exercised by the validity service.
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email');
            $table->string('password'); $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken(); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('draft_id');
            $table->string('status'); $table->string('payment_status');
            $table->decimal('subtotal'); $table->decimal('discount_amount'); $table->decimal('total_amount');
            $table->string('currency'); $table->json('metadata')->nullable();
            $table->string('recharge_status')->nullable(); $table->timestamp('recharge_completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_esims', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('activation_email_sent_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('order_id'); $table->string('type');
            $table->unsignedBigInteger('bundle_id')->nullable();
        });
        Schema::create('trips', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('order_id'); $table->string('destination_country');
            $table->date('arrival_date'); $table->date('departure_date');
            $table->integer('duration_days'); $table->timestamps();
        });
    }

    public function test_expiry_uses_recharge_time_not_later_installation_and_expires_at_the_boundary(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(10, 0));
        $order = $this->order(['recharge_status' => 'success', 'recharge_completed_at' => now()]);
        $assignment = new UserEsim(['user_id' => $order->user_id, 'order_id' => $order->id,
            'device_activated_at' => now()->addDays(3)]);
        $assignment->id = 101;
        $service = app(UserEsimOrderLinkService::class);
        $window = $service->planWindowForAssignment($assignment);
        $this->assertSame('2026-11-12T10:00:00+00:00', $window['plan_expires_at']);
        $this->assertFalse($window['plan_expired']);
        $this->travelTo(now()->addDays(30));
        $this->assertTrue($service->planWindowForAssignment($assignment)['plan_expired']);
    }

    public function test_pending_and_failed_recharges_do_not_start_validity(): void
    {
        foreach (['pending', 'failed', 'queued'] as $status) {
            $order = $this->order(['recharge_status' => $status, 'recharge_completed_at' => now()]);
            $assignment = new UserEsim(['user_id' => $order->user_id, 'order_id' => $order->id]);
            $assignment->id = 101;
            $window = app(UserEsimOrderLinkService::class)->planWindowForAssignment($assignment);
            $this->assertNull($window['plan_started_at']);
            $this->assertNull($window['plan_expires_at']);
        }
    }

    public function test_latest_successful_topup_starts_a_new_window_but_other_sims_and_pending_orders_do_not(): void
    {
        $order = $this->order(['recharge_status' => 'success', 'recharge_completed_at' => '2026-10-01 10:00:00']);
        $assignment = new UserEsim(['user_id' => $order->user_id, 'order_id' => $order->id]);
        $assignment->id = 101;
        $this->order(['user_id' => $order->user_id, 'recharge_status' => 'success',
            'recharge_completed_at' => '2026-10-13 10:00:00', 'metadata' => ['recharge' => ['user_esim_id' => 101]]]);
        $this->order(['user_id' => $order->user_id, 'recharge_status' => 'success',
            'recharge_completed_at' => '2026-10-14 10:00:00', 'metadata' => ['recharge' => ['user_esim_id' => 102]]]);
        $this->order(['user_id' => $order->user_id, 'recharge_status' => 'pending',
            'metadata' => ['recharge' => ['user_esim_id' => 101]]]);
        $window = app(UserEsimOrderLinkService::class)->planWindowForAssignment($assignment);
        $this->assertSame('2026-11-12T10:00:00+00:00', $window['plan_expires_at']);
    }

    public function test_rescheduling_replaces_a_legacy_seven_day_span_with_thirty_days(): void
    {
        $order = $this->order(['metadata' => ['simType' => 'esim']]);
        Trip::create(['order_id' => $order->id, 'destination_country' => 'TZ',
            'arrival_date' => now()->toDateString(), 'departure_date' => now()->addDays(7)->toDateString(),
            'duration_days' => 7]);
        $date = now()->addDays(2);
        $updated = app(SimAssignmentService::class)->rescheduleActivationDate($order, $date->toDateString());
        $this->assertSame($date->copy()->addDays(30)->toDateString(), $updated->trip->departure_date->toDateString());
        $this->assertSame(30, $updated->trip->duration_days);
        $payload = app(UserEsimOrderLinkService::class)->formatBundlePayload(null, new OrderItem(['validity_days' => 7]));
        $this->assertSame(30, $payload['validity_days']);
    }

    public function test_scheduler_selects_paid_due_orders_and_skips_future_unpaid_and_finished_orders(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 21:01:00', 'UTC'));
        $due = $this->order(['metadata' => ['simType' => 'esim']]);
        $future = $this->order(['metadata' => ['simType' => 'esim']]);
        $unpaid = $this->order(['status' => 'pending', 'payment_status' => 'pending']);
        $finished = $this->order(['recharge_status' => 'success']);
        $orphan = $this->order([]);
        $orphan->update(['user_id' => null]);
        $missingUser = $this->order([]);
        $missingUser->update(['user_id' => 999999]);
        foreach ([$due, $future, $unpaid, $finished, $orphan, $missingUser] as $order) {
            Trip::create(['order_id' => $order->id, 'destination_country' => 'TZ',
                'arrival_date' => $order->is($future) ? '2026-10-15' : '2026-10-14',
                'departure_date' => '2026-11-14', 'duration_days' => 30]);
        }
        \Illuminate\Support\Facades\DB::table('user_esims')->insert([
            'order_id' => $finished->id, 'activation_email_sent_at' => now(),
        ]);
        $this->mock(\App\Services\Esim\ScheduledEsimActivationService::class, function ($mock) use ($due) {
            $mock->shouldReceive('activateOrder')->once()->withArgs(fn ($order) => $order->is($due))
                ->andReturn(['status' => 'ready']);
        });
        $this->artisan('esim:activate-due')->expectsOutput('eSIM orders ready: 1')->assertExitCode(0);
    }

    private function order(array $attributes): Order
    {
        return Order::create(array_merge([
            'user_id' => $attributes['user_id'] ?? User::factory()->create()->id,
            'draft_id' => 'VALIDITY-'.uniqid(), 'status' => 'paid', 'payment_status' => 'paid',
            'subtotal' => 25, 'discount_amount' => 0, 'total_amount' => 25, 'currency' => 'USD',
        ], $attributes));
    }
}
