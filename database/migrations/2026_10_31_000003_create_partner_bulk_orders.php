<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_bulk_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_application_id')->constrained('partner_applications')->restrictOnDelete();
            $t->foreignId('bundle_id')->constrained('bundles')->restrictOnDelete();
            $t->foreignId('payment_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $t->uuid('request_key'); $t->unique(['partner_application_id', 'request_key']);
            $t->string('reference', 64)->unique(); $t->string('bundle_name'); $t->string('bundle_product_id', 100);
            $t->unsignedInteger('network_id'); $t->unsignedInteger('validity_days'); $t->unsignedInteger('data_mb')->nullable();
            $t->string('sim_type', 12); $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('catalog_unit_cents'); $t->unsignedSmallInteger('discount_bps');
            $t->unsignedBigInteger('unit_cents'); $t->unsignedBigInteger('total_cents');
            $t->string('status', 24)->default('pending_payment'); $t->unsignedInteger('version')->default(0);
            $t->string('payment_reference', 160)->nullable()->unique();
            $t->string('received_currency', 3)->nullable(); $t->unsignedBigInteger('received_cents')->nullable();
            $t->text('message')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamps();
            $t->index(['partner_application_id', 'id']);
        });
        Schema::table('esims', function (Blueprint $t) {
            $t->foreignId('partner_bulk_order_id')->nullable()->constrained('partner_bulk_orders')->restrictOnDelete();
        });
        Schema::create('partner_stock_units', function (Blueprint $t) {
            $t->id(); $t->foreignId('partner_bulk_order_id')->constrained('partner_bulk_orders')->restrictOnDelete();
            $t->foreignId('esim_id')->unique()->constrained('esims')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $t->foreignId('user_esim_id')->nullable()->unique()->constrained('user_esims')->restrictOnDelete();
            $t->text('guest')->nullable(); $t->timestamp('handed_over_at')->nullable();
            $t->unsignedInteger('version')->default(0); $t->timestamps();
        });
        Schema::create('partner_bulk_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('partner_bulk_order_id')->constrained('partner_bulk_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('stock_unit_id')->nullable(); $t->string('actor_type', 12); $t->unsignedBigInteger('actor_id');
            $t->string('action', 32); $t->text('message')->nullable(); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bulk_events'); Schema::dropIfExists('partner_stock_units');
        Schema::table('esims', fn (Blueprint $t) => $t->dropConstrainedForeignId('partner_bulk_order_id'));
        Schema::dropIfExists('partner_bulk_orders');
    }
};
