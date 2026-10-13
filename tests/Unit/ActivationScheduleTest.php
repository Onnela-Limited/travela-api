<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Trip;
use App\Services\Esim\SimAssignmentService;
use App\Support\ActivationSchedule;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ActivationScheduleTest extends TestCase
{
    public function test_activation_day_changes_at_tanzania_midnight_not_utc_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 20:59:00', 'UTC'));
        $this->assertSame('2026-10-13', ActivationSchedule::today());
        $order = new Order;
        $order->setRelation('trip', new Trip(['arrival_date' => '2026-10-14']));
        $service = app(SimAssignmentService::class);
        $this->assertFalse($service->activationDateIsDue($order));
        $this->travelTo(Carbon::parse('2026-10-13 21:00:00', 'UTC'));
        $this->assertFalse($service->activationDateIsDue($order));
        $this->travelTo(Carbon::parse('2026-10-13 21:01:00', 'UTC'));
        $this->assertSame('2026-10-14', ActivationSchedule::today());
        $this->assertTrue($service->activationDateIsDue($order));
    }

    public function test_automatic_activation_scheduler_runs_every_minute_in_tanzania_time(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event) => str_contains($event->command ?? '', 'esim:activate-due')
        );
        $this->assertNotNull($event);
        $this->assertSame(ActivationSchedule::TIMEZONE, $event->timezone);
        $this->travelTo(Carbon::parse('2026-10-13 21:00:00', 'UTC'));
        $this->assertTrue($event->isDue(app()));
        $this->travelTo(Carbon::parse('2026-10-13 21:01:00', 'UTC'));
        $this->assertTrue($event->isDue(app()));
        $this->travelTo(Carbon::parse('2026-10-13 21:02:00', 'UTC'));
        $this->assertTrue($event->isDue(app()));
    }
}
