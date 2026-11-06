<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->uuid('number_assignment_request_id')->nullable()->unique();
            $table->unsignedBigInteger('number_assigned_by')->nullable();
            $table->unsignedBigInteger('previous_user_esim_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_esims', function (Blueprint $table) {
            $table->dropUnique(['number_assignment_request_id']);
            $table->dropIndex(['previous_user_esim_id']);
            $table->dropColumn(['number_assignment_request_id', 'number_assigned_by', 'previous_user_esim_id']);
        });
    }
};
