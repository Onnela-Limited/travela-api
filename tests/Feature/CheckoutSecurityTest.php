<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\User;
use App\Services\EvPay\EvPayCheckoutService;
use App\Services\EvPay\EvPayClientService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutSecurityTest extends TestCase
{
    private Bundle $bundle;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role')->default('user');
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('bundles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('price_usd');
            $t->boolean('active');
            $t->integer('data_mb');
            $t->integer('validity_days');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->string('draft_id')->unique();
            $t->string('status');
            foreach (['subtotal', 'discount_amount', 'total_amount'] as $field) {
                $t->decimal($field);
            }
            foreach (['currency', 'discount_code', 'source', 'platform', 'payment_status', 'payment_reference'] as $field) {
                $t->string($field)->nullable();
            }
            $t->json('metadata')->nullable();
            $t->json('payment_callback')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->integer('order_id');
            $t->integer('bundle_id')->nullable();
            $t->string('type');
            $t->string('bundle_name');
            $t->integer('data_amount')->nullable();
            $t->integer('validity_days');
            $t->decimal('price');
            $t->string('currency');
            $t->timestamps();
        });
        Schema::create('trips', function (Blueprint $t) {
            $t->id();
            $t->integer('order_id');
            $t->string('destination_country');
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->integer('duration_days');
            $t->timestamps();
        });
        Schema::create('kycs', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            foreach (['passport_country', 'nationality', 'gender', 'reason'] as $field) {
                $t->string($field);
            }
            $t->text('passport_id_encrypted')->nullable();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->timestamps();
        });
        $this->customer = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($this->customer);
        $this->bundle = Bundle::create(['name' => '50GB (30 days)', 'price_usd' => 50,
            'active' => true, 'data_mb' => 51200, 'validity_days' => 30]);
    }

    public function test_one_dollar_payload_and_forged_discount_are_priced_at_fifty_dollars(): void
    {
        $this->mock(EvPayCheckoutService::class, function ($mock) {
            $mock->shouldReceive('startCardPayment')->once()->withArgs(function (Order $order) {
                $this->assertSame('50.00', $order->total_amount);
                $this->assertSame('0.00', $order->discount_amount);
                $this->assertNull($order->discount_code);
                $item = $order->orderItems->first();
                $this->assertSame('50GB (30 days)', $item->bundle_name);
                $this->assertEquals(51200, $item->data_amount);
                $this->assertEquals(50, $item->price);
                $this->assertEquals(30, $item->validity_days);
                $this->assertSame('USD', $item->currency);

                return true;
            })->andReturn(['checkout_url' => 'https://payments.example/test']);
            $mock->shouldReceive('assignmentSummary')->never();
        });
        $this->postJson('/api/orders', $this->payload())->assertCreated()->assertJsonPath('data.total_amount', '50.00');
    }

    public function test_inactive_hidden_and_non_catalog_service_items_are_rejected(): void
    {
        $this->bundle->update(['active' => false]);
        $this->postJson('/api/orders', $this->payload())->assertUnprocessable();
        $this->bundle->update(['active' => true, 'metadata' => ['admin_only' => true]]);
        $this->postJson('/api/orders', $this->payload())->assertUnprocessable();
        $payload = $this->payload();
        $payload['items'][0]['type'] = 'service';
        $this->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_another_customer_cannot_read_update_delete_or_reuse_an_order(): void
    {
        $order = $this->order(User::factory()->create()->id);
        $this->getJson('/api/orders/'.$order->draft_id)->assertForbidden();
        $this->putJson('/api/orders/'.$order->draft_id, $this->payload())->assertForbidden();
        $this->deleteJson('/api/orders/'.$order->draft_id)->assertForbidden();
        $this->postJson('/api/orders', $this->payload())->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_amount' => 50]);
    }

    public function test_customer_cannot_swap_bundle_or_delete_order_after_checkout_started(): void
    {
        $order = $this->order($this->customer->id);
        $this->putJson('/api/orders/'.$order->draft_id, $this->payload())->assertStatus(409);
        $this->deleteJson('/api/orders/'.$order->draft_id)->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_amount' => 50]);
    }

    public function test_legacy_pending_orders_cannot_start_or_reuse_a_payment_session(): void
    {
        $order = $this->order($this->customer->id);
        $this->postJson('/api/orders/'.$order->id.'/evpay-checkout-url')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This order requires pricing review. Please create a new checkout.');
    }

    public function test_valid_gateway_payment_cannot_fulfill_a_legacy_underpriced_order(): void
    {
        $order = $this->order($this->customer->id);
        $order->update(['payment_reference' => 'LEGACY-1', 'total_amount' => 1]);
        $this->mock(EvPayClientService::class, function ($mock) {
            $mock->shouldReceive('verifiedWebhookPayload')->once()->andReturn([
                'event' => 'payment.succeeded',
                'data' => ['orderReference' => 'LEGACY-1', 'amount' => 1, 'currency' => 'USD', 'status' => 'SUCCESS'],
            ]);
        });
        $this->postJson('/api/payments/evpay/callback')->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    private function order(int $userId): Order
    {
        return Order::create(['draft_id' => 'SECURITY-TEST', 'user_id' => $userId,
            'status' => 'pending_payment', 'payment_status' => 'pending', 'subtotal' => 50,
            'discount_amount' => 0, 'total_amount' => 50, 'currency' => 'USD']);
    }

    private function payload(): array
    {
        return [
            'draft_id' => 'SECURITY-TEST', 'user_id' => $this->customer->id, 'simType' => 'esim',
            'trip' => ['destination_country' => 'TZ', 'arrival_date' => '2026-11-01',
                'departure_date' => '2026-11-05', 'duration_days' => 4],
            'kyc' => ['passport_id' => 'TEST123', 'passport_country' => 'TZ', 'nationality' => 'Tanzanian',
                'gender' => 'Male', 'reason_for_travel' => 'Tourism'],
            'items' => [['type' => 'bundle', 'bundle_id' => $this->bundle->id, 'bundle_name' => 'Fake',
                'data_amount' => 999999, 'validity_days' => 999, 'price' => 1, 'currency' => 'EUR']],
            'pricing' => ['subtotal' => 50, 'discount_amount' => 49, 'discount_code' => 'FAKE',
                'total_amount' => 1, 'currency' => 'EUR'],
            'order_metadata' => ['source' => 'web', 'platform' => 'web', 'created_at' => now()->toIso8601String()],
        ];
    }
}
