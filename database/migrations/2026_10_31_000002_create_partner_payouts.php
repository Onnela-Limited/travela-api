<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->constrained('partner_applications')->restrictOnDelete();
            $table->uuid('request_key');
            $table->unique(['partner_application_id', 'request_key']);
            $table->string('reference', 64)->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->text('destination');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('processing_admin_id')->nullable();
            $table->string('processing_admin_name')->nullable();
            $table->string('payment_reference', 160)->nullable()->unique();
            $table->string('transfer_currency', 3)->nullable();
            $table->unsignedBigInteger('transfer_amount_cents')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['partner_application_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('partner_payout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_payout_id')->constrained('partner_payouts')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payout_events');
        Schema::dropIfExists('partner_payouts');
    }
};
