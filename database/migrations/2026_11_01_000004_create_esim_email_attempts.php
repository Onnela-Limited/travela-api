<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esim_email_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_esim_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('recipient');
            $table->string('provider_id')->nullable();
            $table->string('status')->default('sending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esim_email_attempts');
    }
};
