<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_bundle_recharges', function (Blueprint $table) {
            $table->unsignedBigInteger('data_mb')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_bundle_recharges', fn (Blueprint $table) => $table->dropColumn(['data_mb', 'completed_at']));
    }
};
