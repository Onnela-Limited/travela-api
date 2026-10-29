<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->unsignedInteger('review_version')->default(0);
            $table->text('review_message')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::create('partner_application_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();
            // Preserve who made a decision even if that admin account is later removed.
            $table->unsignedBigInteger('reviewer_id');
            $table->string('reviewer_name');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_application_reviews');
        Schema::table('partner_applications', fn (Blueprint $table) => $table->dropColumn(['review_version', 'review_message', 'reviewed_at']));
    }
};
