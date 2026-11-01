<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('partner_applications',fn(Blueprint $t)=>$t->unsignedInteger('settings_version')->default(0));
  Schema::create('partner_settings_events',function(Blueprint $t){$t->id();$t->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();$t->unsignedBigInteger('actor_session_id');$t->unsignedInteger('version');$t->text('changes');$t->timestamp('created_at');});
 }
 public function down(): void {Schema::dropIfExists('partner_settings_events');Schema::table('partner_applications',fn(Blueprint $t)=>$t->dropColumn('settings_version'));}
};
