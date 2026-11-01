<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesVodacomTestSchema
{
    protected function setUpVodacomTestSchema(): void
    {
        Schema::dropIfExists('esims');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('user');
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('esims', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_bulk_order_id')->nullable();
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sim_id')->nullable();
            $table->unsignedBigInteger('vodacom_sim_id')->nullable();
            $table->string('msisdn')->unique();
            $table->unsignedInteger('network_id')->nullable();
            $table->string('description')->nullable();
            $table->string('status')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('vodacom_status')->nullable();
            $table->string('activation_status', 32)->default('pending');
            $table->text('activation_error')->nullable();
            $table->json('vodacom_create_response')->nullable();
            $table->json('vodacom_activation_response')->nullable();
            $table->timestamp('vodacom_activated_at')->nullable();
            $table->timestamps();
        });
    }
}
