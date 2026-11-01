<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_team_members', function (Blueprint $t) {
            $t->id(); $t->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();
            $t->uuid('request_key'); $t->unique(['partner_application_id','request_key']);
            $t->char('email_hash',64)->index();
            // A revoked membership releases its email for a new invitation/team.
            $t->char('active_email_hash',64)->nullable()->unique();
            $t->text('details'); $t->string('role',20); $t->string('status',20)->default('invited');
            $t->unsignedInteger('version')->default(0); $t->char('invitation_hash',64)->nullable()->unique();
            $t->timestamp('invitation_expires_at')->nullable(); $t->timestamp('last_sent_at')->nullable();
            $t->string('delivery_status',20)->default('pending'); $t->timestamp('accepted_at')->nullable(); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
            $t->index(['partner_application_id','status']);
        });
        Schema::create('partner_team_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('partner_team_member_id')->constrained('partner_team_members')->cascadeOnDelete();
            $t->string('actor_type',12); $t->unsignedBigInteger('actor_id'); $t->string('action',32);
            $t->text('details')->nullable(); $t->timestamp('created_at');
        });
        Schema::table('partner_login_challenges', function (Blueprint $t) {
            // Cascade rather than null-on-delete: deleting staff must never turn a staff grant into an owner grant.
            $t->foreignId('partner_team_member_id')->nullable()->constrained('partner_team_members')->cascadeOnDelete();
            $t->unsignedInteger('team_version')->nullable();
        });
        Schema::table('partner_sessions', function (Blueprint $t) {
            $t->foreignId('partner_team_member_id')->nullable()->constrained('partner_team_members')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('partner_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('partner_team_member_id'));
        Schema::table('partner_login_challenges', function (Blueprint $t) { $t->dropConstrainedForeignId('partner_team_member_id'); $t->dropColumn('team_version'); });
        Schema::dropIfExists('partner_team_events'); Schema::dropIfExists('partner_team_members');
    }
};
