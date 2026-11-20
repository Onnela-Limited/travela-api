<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Challenge IDs rotate on resend. Expired snapshots are pruned during automatic code requests.
        Schema::create('partner_login_choices', function(Blueprint $table) {
            $table->uuid('challenge_id')->primary();
            $table->text('accounts');
            $table->char('selection_token_hash',64)->nullable();
            $table->timestamp('selection_expires_at')->nullable();
            $table->timestamp('created_at')->index();
        });
    }
    public function down(): void { Schema::dropIfExists('partner_login_choices'); }
};
