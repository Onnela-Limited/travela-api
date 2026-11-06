<?php

namespace Tests\Feature;

use App\Models\{Esim, Order, OrderItem, User, UserEsim};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

class CustomerNumberAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        if (config('database.default') !== 'sqlite') return [];
        $mysqlOnly = ['2026_07_25_000001_add_skipped_status_to_esim_import_items_table.php', '2026_07_25_000002_add_pending_provider_status_to_esims_table.php'];
        return ['--realpath' => true, '--path' => array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! in_array(basename($path), $mysqlOnly, true)))];
    }

    private function fixture(): array
    {
        Mail::fake(); Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $user = User::factory()->create(['role' => 'user']);
        $old = Esim::create(['msisdn' => '255700000001', 'network_id' => 1, 'sim_type' => 'esim', 'status' => 'MANAGED', 'qr_code_data' => 'OLD-QR']);
        $order = Order::create(['draft_id' => 'CHANGE-'.uniqid(), 'user_id' => $user->id, 'status' => 'paid', 'payment_status' => 'paid',
            'subtotal' => 20, 'discount_amount' => 0, 'total_amount' => 20, 'currency' => 'USD', 'paid_at' => now(),
            'recharge_status' => 'success', 'recharge_completed_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'type' => 'bundle', 'bundle_name' => 'Old 24GB', 'data_amount' => 24576, 'price' => 20, 'currency' => 'USD']);
        $assignment = UserEsim::create(['user_id' => $user->id, 'esim_id' => $old->id, 'order_id' => $order->id,
            'balances' => ['DATA' => 24576], 'device_activated_at' => now(), 'activation_email_sent_at' => now()]);
        $new = Esim::create(['msisdn' => '255700000002', 'network_id' => 1, 'sim_type' => 'esim', 'status' => 'AVAILABLE',
            'sale_status' => 'available', 'provider_status' => 'active', 'qr_code_data' => 'NEW-QR']);
        return [$user, $assignment, $new, $admin, $order];
    }

    private function url(User $user, string $action = 'number-assignments'): string { return '/api/admin/customers/'.$user->id.'/'.$action; }
    private function payload(UserEsim $previous, Esim $new): array { return ['previous_user_esim_id' => $previous->id, 'esim_id' => $new->id, 'request_id' => '0b07c735-ae04-46ad-9252-ad72e467ac4f']; }

    public function test_assignment_preserves_original_history_and_does_not_copy_bundle_or_send_requests(): void
    {
        [$user, $previous, $new, $admin, $order] = $this->fixture();
        $before = $previous->fresh()->getRawOriginal(); $orderBefore = $order->fresh()->getRawOriginal();
        $response = $this->postJson($this->url($user), $this->payload($previous, $new))->assertOk()->assertJsonPath('new_number', '255700000002');
        $assigned = UserEsim::findOrFail($response->json('user_esim_id'));
        $this->assertSame($admin->id, $assigned->number_assigned_by);
        $this->assertSame($previous->id, $assigned->previous_user_esim_id);
        $this->assertNull($assigned->order_id); $this->assertNull($assigned->bundle_id); $this->assertNull($assigned->balances);
        $this->assertNull($assigned->device_activated_at);
        $this->assertSame($before, $previous->fresh()->getRawOriginal());
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame('MANAGED', $new->fresh()->status);
        $this->assertFalse(Esim::availableForAssignment()->whereKey($previous->esim_id)->exists());
        $this->actingAs($user);
        $this->getJson('/api/me/esims')->assertOk()->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonPath('data.0.number_assignment_only', true)->assertJsonPath('data.0.bundle', null)
            ->assertJsonPath('data.0.plan_started_at', null)->assertJsonPath('data.0.bundle_history', [])
            ->assertJsonPath('data.0.esim.msisdn', '255700000002');
        $this->assertNull($assigned->fresh()->order_id);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }

    public function test_replay_is_idempotent_and_changed_payload_cannot_reuse_request(): void
    {
        [$user, $previous, $new] = $this->fixture(); $payload = $this->payload($previous, $new);
        $id = $this->postJson($this->url($user), $payload)->assertOk()->json('user_esim_id');
        $this->postJson($this->url($user), $payload)->assertOk()->assertJsonPath('user_esim_id', $id);
        $this->assertSame(2, UserEsim::where('user_id', $user->id)->count());
        $payload['esim_id'] = $previous->esim_id;
        $this->postJson($this->url($user), $payload)->assertStatus(409);
        $this->deleteJson('/api/admin/user-esims/'.$previous->id)->assertStatus(409);
    }

    public function test_unavailable_incompatible_or_missing_qr_numbers_are_rejected(): void
    {
        [$user, $previous, $new] = $this->fixture();
        foreach ([['status' => 'MANAGED'], ['sale_status' => 'sold'], ['network_id' => 2], ['qr_code_data' => ' '], ['provider_status' => 'suspended'], ['sim_type' => 'physical']] as $change) {
            $original = $new->only(array_keys($change)); $new->update($change);
            $this->postJson($this->url($user), $this->payload($previous, $new))->assertStatus(409);
            $new->update($original);
        }
        UserEsim::create(['user_id' => User::factory()->create()->id, 'esim_id' => $new->id]);
        $this->postJson($this->url($user), $this->payload($previous, $new))->assertStatus(409);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }

    public function test_admin_role_ownership_and_uuid_are_required(): void
    {
        [$user, $previous, $new, $admin] = $this->fixture();
        $this->actingAs($user);
        $this->postJson($this->url($user), $this->payload($previous, $new))->assertForbidden();
        $this->getJson($this->url($user, 'available-numbers').'?user_esim_id='.$previous->id)->assertForbidden();
        $this->actingAs($admin);
        $other = User::factory()->create(['role' => 'user']);
        $this->postJson($this->url($other), $this->payload($previous, $new))->assertNotFound();
        $this->postJson($this->url($user), array_merge($this->payload($previous, $new), ['request_id' => 'bad']))->assertUnprocessable();
    }

    public function test_available_number_search_excludes_assigned_stock_and_activation_secrets(): void
    {
        [$user, $previous, $new] = $this->fixture();
        $response = $this->getJson($this->url($user, 'available-numbers').'?user_esim_id='.$previous->id.'&search=000002')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new->id);
        $this->assertArrayNotHasKey('qr_code_data', $response->json('data.0'));
        UserEsim::create(['user_id' => User::factory()->create()->id, 'esim_id' => $new->id]);
        $this->getJson($this->url($user, 'available-numbers').'?user_esim_id='.$previous->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->url($user), $this->payload($previous, $new))->assertStatus(409);
    }
}
