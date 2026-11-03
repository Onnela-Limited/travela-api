<?php

namespace Tests\Feature;

use App\Mail\EsimRechargeConfirmationMail;
use App\Models\{Bundle, BundleType, Country, CountryProvider, Esim, Provider, User, UserEsim};
use App\Services\Esim\{VodacomBalanceService, VodacomSimManagerService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

class CustomerBundleRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        if (config('database.default') !== 'sqlite') return [];
        // These historical migrations only widen MySQL ENUMs; SQLite cannot run MODIFY.
        $mysqlOnly = ['2026_07_25_000001_add_skipped_status_to_esim_import_items_table.php',
            '2026_07_25_000002_add_pending_provider_status_to_esims_table.php'];
        return ['--realpath' => true, '--path' => array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn ($path) => ! in_array(basename($path), $mysqlOnly, true),
        ))];
    }

    public function test_success_emails_customer_and_duplicate_submission_does_not_recharge_or_email_again(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->provider(['status' => 'SUCCESS']);
        $payload = $this->payload($sim, $bundle);
        $this->postJson($this->url($user), $payload)->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('email_status', 'sent');
        $this->postJson($this->url($user), $payload)->assertOk()->assertJsonPath('email_status', 'sent');
        Mail::assertSent(EsimRechargeConfirmationMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->bundleName === $bundle->name && $mail->msisdn === '255797053059');
        Mail::assertSentCount(1);
    }

    public function test_queued_request_emails_only_after_successful_matching_callback_and_only_once(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->provider(['message' => 'queued for callback'], 202);
        $oldOrder = \App\Models\Order::create([
            'draft_id' => 'OLD-ORDER', 'user_id' => $user->id, 'status' => 'paid', 'payment_status' => 'paid',
            'subtotal' => 90, 'discount_amount' => 0, 'total_amount' => 90, 'currency' => 'USD',
            'paid_at' => now(), 'recharge_status' => 'success', 'recharge_reference' => 'OLD-REFERENCE',
        ]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertOk()->assertJsonPath('status', 'pending');
        Mail::assertNothingSent();
        $this->sendCallback('FAILED')->assertOk();
        Mail::assertNothingSent();
        $this->sendCallback('SUCCEEDED', '255700000000')->assertStatus(404);
        Mail::assertNothingSent();
        $this->sendCallback('SUCCEEDED', '255797053059', 999)->assertOk();
        Mail::assertNothingSent();
        $this->sendCallback('SUCCEEDED')->assertOk();
        $this->sendCallback('SUCCEEDED')->assertOk();
        Mail::assertSentCount(1);
        $this->assertNull($oldOrder->fresh()->recharge_response);
        $this->assertDatabaseHas('customer_bundle_recharges', ['reference' => 'RECHARGE-test', 'status' => 'success', 'email_status' => 'sent']);
    }

    public function test_failed_or_unconfirmed_provider_results_do_not_send_email(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->provider(['status' => 'FAILED']);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertOk()->assertJsonPath('status', 'failed');
        Mail::assertNothingSent();
    }

    public function test_empty_success_response_waits_for_confirmation(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->provider([]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertOk()->assertJsonPath('status', 'pending');
        Mail::assertNothingSent();
    }

    public function test_customer_cannot_use_admin_recharge_endpoint(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->actingAs($user);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_number_must_belong_to_selected_customer(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $other = User::factory()->create(['role' => 'user']);
        $this->postJson($this->url($other), $this->payload($sim, $bundle))->assertNotFound();
        $this->assertDatabaseCount('customer_bundle_recharges', 0);
        Mail::assertNothingSent();
    }

    public function test_inactive_or_incompatible_bundle_is_rejected_before_recharging(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $bundle->update(['network_id' => 2]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertUnprocessable();
        $bundle->update(['network_id' => 1, 'active' => false]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertUnprocessable();
        $this->assertDatabaseCount('customer_bundle_recharges', 0);
    }

    public function test_mail_failure_keeps_recharge_successful_and_does_not_recharge_on_retry(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $this->provider(['status' => 'SUCCESS']);
        Mail::shouldReceive('to')->once()->with($user->email)->andThrow(new \RuntimeException('Mail timeout'));
        $payload = $this->payload($sim, $bundle);
        $this->postJson($this->url($user), $payload)->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('email_status', 'unconfirmed');
        $this->postJson($this->url($user), $payload)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_catalog_resolves_missing_vodacom_network_without_exposing_provider_metadata(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $bundle->update(['network_id' => null]);
        $this->getJson('/api/public/bundles?active=1')->assertOk()
            ->assertJsonPath('bundles.0.network_id', 1)->assertJsonMissingPath('bundles.0.country_provider');
        $this->assertNull($bundle->fresh()->network_id);
    }

    public function test_recharge_accepts_missing_bundle_network_using_its_vodacom_provider(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $bundle->update(['network_id' => null]);
        $this->provider(['status' => 'SUCCESS']);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertOk()->assertJsonPath('status', 'success');
        Mail::assertSentCount(1);
    }

    public function test_missing_bundle_network_cannot_match_an_unknown_or_different_provider(): void
    {
        [$user, $sim, $bundle] = $this->fixture();
        $bundle->update(['network_id' => null]);
        $provider = $bundle->countryProvider->provider;
        $provider->update(['slug' => 'other', 'metadata' => ['network_id' => 2]]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertUnprocessable();
        $provider->update(['metadata' => []]);
        $this->postJson($this->url($user), $this->payload($sim, $bundle))->assertUnprocessable();
        $this->assertDatabaseCount('customer_bundle_recharges', 0);
        Mail::assertNothingSent();
    }

    private function fixture(): array
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $user = User::factory()->create(['role' => 'user']);
        $esim = Esim::create(['msisdn' => '255797053059', 'network_id' => 1, 'status' => 'MANAGED', 'sim_type' => 'physical']);
        $sim = UserEsim::create(['user_id' => $user->id, 'esim_id' => $esim->id]);
        $type = BundleType::firstOrCreate(['code' => 'DATA'], ['name' => 'Data only']);
        $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA']);
        $provider = Provider::create(['name' => 'Vodacom', 'slug' => 'vodacom']);
        $pivot = CountryProvider::create(['country_id' => $country->id, 'provider_id' => $provider->id, 'is_default' => true]);
        $bundle = Bundle::create(['bundle_type_id' => $type->id, 'country_provider_id' => $pivot->id,
            'network_id' => 1, 'name' => 'Nomad 10GB', 'validity_days' => 30, 'price_usd' => 90,
            'currency' => 'USD', 'sim_bundle_id' => 66, 'active' => true]);
        $this->mock(VodacomBalanceService::class, function ($mock) {
            $mock->shouldReceive('requestBalancesForMsisdn')->zeroOrMoreTimes()->andReturn(['status' => 'queued', 'http_status' => 202]);
        });
        return [$user, $sim, $bundle];
    }

    private function provider(array $response, int $status = 200): void
    {
        $this->mock(VodacomSimManagerService::class, function ($mock) use ($response, $status) {
            $mock->shouldReceive('post')->once()->with('/api/recharge', [], \Mockery::on(fn ($payload) =>
                $payload['product_id'] === 66 && $payload['network_id'] === 1 && $payload['msisdn'] === '255797053059'
                && $payload['reference'] === 'RECHARGE-test'))->andReturn(new \Illuminate\Http\Client\Response(Http::response($response, $status)->wait()));
        });
    }

    private function payload(UserEsim $sim, Bundle $bundle): array
    {
        return ['user_esim_id' => $sim->id, 'bundle_id' => $bundle->id, 'reference' => 'RECHARGE-test'];
    }

    private function url(User $user): string
    {
        return '/api/admin/customers/'.$user->id.'/bundle-recharges';
    }

    private function sendCallback(string $status, string $number = '255797053059', int $product = 66)
    {
        return $this->postJson('/api/public/my-recharge-callback-url', ['data' => [
            'reference' => 'RECHARGE-test', 'status' => $status, 'msisdn' => $number, 'product_id' => $product,
        ]]);
    }
}
