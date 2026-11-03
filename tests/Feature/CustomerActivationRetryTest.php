<?php

namespace Tests\Feature;

use App\Mail\EsimActivationRetryMail;
use App\Models\{Esim, Order, OrderItem, User, UserEsim};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Mail};
use Tests\TestCase;

class CustomerActivationRetryTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_ID = 'a3e65b71-1742-47f0-927e-ffac70d4e07b';

    protected function migrateFreshUsing(): array
    {
        if (config('database.default') !== 'sqlite') return [];
        $mysqlOnly = ['2026_07_25_000001_add_skipped_status_to_esim_import_items_table.php',
            '2026_07_25_000002_add_pending_provider_status_to_esims_table.php'];
        return ['--realpath' => true, '--path' => array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => ! in_array(basename($path), $mysqlOnly, true)))];
    }

    public function test_admin_restores_activation_emails_correct_number_and_preserves_bundle_balance_and_plan(): void
    {
        [$user, $sim, $order] = $this->fixture();
        $before = $sim->only(['esim_id', 'user_id', 'order_id', 'bundle_id', 'balances', 'last_recharge_reference']);
        $simBefore = $sim->esim->getAttributes();
        $expires = app(\App\Services\Esim\UserEsimOrderLinkService::class)->planWindowForAssignment($sim)['plan_expires_at'];
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertOk()
            ->assertJsonPath('activation_enabled', true)->assertJsonPath('email_status', 'sent');
        $this->assertNull($sim->fresh()->device_activated_at);
        $this->assertSame($before, $sim->fresh()->only(array_keys($before)));
        $this->assertSame($simBefore, $sim->esim->fresh()->getAttributes());
        $this->assertSame($expires, app(\App\Services\Esim\UserEsimOrderLinkService::class)->planWindowForAssignment($sim->fresh())['plan_expires_at']);
        Mail::assertSent(EsimActivationRetryMail::class, function ($mail) use ($user, $sim) {
            parse_str(parse_url($mail->dashboardUrl, PHP_URL_QUERY), $params);
            return $mail->hasTo($user->email) && $mail->msisdn === '255797053059' &&
                $params['retry_activation'] === '1' && (int) $params['user_esim_id'] === $sim->id &&
                $params['msisdn'] === '255797053059';
        });
        $this->actingAs($user);
        $this->getJson('/api/me/esims')->assertOk()->assertJsonPath('data.0.device_activated_at', null)
            ->assertJsonPath('data.0.esim.qr_code_data', 'LPA:1$smdp.example.com$RETRY');
        $this->postJson('/api/me/esims/'.$sim->id.'/device-activated')->assertCreated();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        Mail::assertSentCount(1);
    }

    public function test_repeated_request_does_not_email_twice_or_reset_a_later_successful_installation(): void
    {
        [$user, $sim, $order, $admin] = $this->fixture();
        $payload = ['request_id' => self::REQUEST_ID];
        $this->postJson($this->url($user, $sim), $payload)->assertOk();
        $this->actingAs($user);
        $this->postJson('/api/me/esims/'.$sim->id.'/device-activated')->assertCreated();
        $installed = $sim->fresh()->device_activated_at->toIso8601String();
        $this->actingAs($admin);
        $this->postJson($this->url($user, $sim), $payload)->assertOk()->assertJsonPath('email_status', 'sent');
        $this->assertSame($installed, $sim->fresh()->device_activated_at->toIso8601String());
        Mail::assertSentCount(1);
    }

    public function test_non_admin_cannot_reopen_activation(): void
    {
        [$user, $sim] = $this->fixture();
        $this->actingAs($user);
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertForbidden();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        Mail::assertNothingSent();
    }

    public function test_number_must_belong_to_selected_customer(): void
    {
        [$user, $sim] = $this->fixture();
        $other = User::factory()->create(['role' => 'user']);
        $this->postJson($this->url($other, $sim), ['request_id' => self::REQUEST_ID])->assertNotFound();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        Mail::assertNothingSent();
    }

    public function test_physical_sim_or_missing_installation_details_cannot_be_reopened(): void
    {
        [$user, $sim] = $this->fixture();
        $sim->esim->update(['sim_type' => 'physical']);
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertUnprocessable();
        $sim->esim->update(['sim_type' => 'esim', 'qr_code_data' => null]);
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertUnprocessable();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        Mail::assertNothingSent();
    }

    public function test_expired_plan_cannot_be_reopened_without_an_active_bundle(): void
    {
        [$user, $sim, $order] = $this->fixture();
        $order->update(['recharge_completed_at' => now()->subDays(31)]);
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertUnprocessable();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        Mail::assertNothingSent();
    }

    public function test_email_failure_keeps_activation_enabled_and_lost_response_retry_does_not_send_again(): void
    {
        [$user, $sim] = $this->fixture();
        Mail::shouldReceive('to')->once()->with($user->email)->andThrow(new \RuntimeException('Mail timed out'));
        $payload = ['request_id' => self::REQUEST_ID];
        $this->postJson($this->url($user, $sim), $payload)->assertOk()
            ->assertJsonPath('activation_enabled', true)->assertJsonPath('email_status', 'unconfirmed');
        $this->postJson($this->url($user, $sim), $payload)->assertOk()->assertJsonPath('email_status', 'unconfirmed');
        $this->assertNull($sim->fresh()->device_activated_at);
    }

    public function test_new_request_respects_email_cooldown_and_invalid_request_is_rejected(): void
    {
        [$user, $sim] = $this->fixture();
        $this->postJson($this->url($user, $sim), ['request_id' => 'not-a-uuid'])->assertUnprocessable();
        $this->assertNotNull($sim->fresh()->device_activated_at);
        $this->postJson($this->url($user, $sim), ['request_id' => self::REQUEST_ID])->assertOk();
        $this->postJson($this->url($user, $sim), ['request_id' => 'fa91ea0b-4938-4239-99ee-1efdc5c18fba'])->assertStatus(429);
        Mail::assertSentCount(1);
    }

    private function fixture(): array
    {
        Cache::flush();
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $user = User::factory()->create(['role' => 'user']);
        $esim = Esim::create(['msisdn' => '255797053059', 'network_id' => 1, 'status' => 'MANAGED',
            'sim_type' => 'esim', 'qr_code_data' => 'LPA:1$smdp.example.com$RETRY']);
        $order = Order::create(['draft_id' => 'RETRY-'.uniqid(), 'user_id' => $user->id, 'status' => 'paid',
            'payment_status' => 'paid', 'subtotal' => 20, 'discount_amount' => 0, 'total_amount' => 20,
            'currency' => 'USD', 'paid_at' => now()->subDay(), 'recharge_status' => 'success', 'recharge_completed_at' => now()->subDay()]);
        OrderItem::create(['order_id' => $order->id, 'type' => 'bundle', 'bundle_name' => '24GB',
            'data_amount' => 24576, 'price' => 20, 'currency' => 'USD']);
        $sim = UserEsim::create(['user_id' => $user->id, 'esim_id' => $esim->id, 'order_id' => $order->id,
            'device_activated_at' => now()->subHour(), 'balances' => ['DATA' => 24576], 'last_recharge_reference' => 'OLD-REFERENCE']);
        return [$user, $sim, $order, $admin];
    }

    private function url(User $user, UserEsim $sim): string
    {
        return '/api/admin/customers/'.$user->id.'/esims/'.$sim->id.'/allow-activation';
    }
}
