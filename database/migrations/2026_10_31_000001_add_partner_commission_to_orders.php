<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable for legacy orders and negotiated contracts needing review.
            $table->unsignedSmallInteger('partner_commission_bps')->nullable();
            $table->index(['partner_referral_id', 'created_at'], 'orders_partner_sales_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_partner_sales_index');
            $table->dropColumn('partner_commission_bps');
        });
    }
};
