<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_bundle_recharges', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_esim_id');
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('msisdn');
            $table->unsignedBigInteger('network_id');
            $table->unsignedBigInteger('product_id');
            $table->string('bundle_name');
            $table->string('customer_name');
            $table->string('recipient');
            $table->string('status')->default('pending');
            $table->string('email_status')->default('pending');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bundle_recharges');
    }
};
