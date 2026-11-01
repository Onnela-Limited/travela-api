<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  // MySQL can leave a partially applied CREATE after an index-name failure.
  if(Schema::hasTable('partner_notifications')){
   foreach(['id','partner_application_id','partner_team_member_id','event_key','recipient_key','category','content','email','read_at','email_status','attempts','next_attempt_at','sent_at','created_at','updated_at'] as $column)if(!Schema::hasColumn('partner_notifications',$column))throw new \RuntimeException('Unexpected partial notification schema');
   $indexes=collect(Schema::getIndexes('partner_notifications'))->pluck('name')->all();
   Schema::table('partner_notifications',function(Blueprint $t)use($indexes){
    if(!in_array('pn_recipient_category',$indexes))$t->index(['partner_application_id','partner_team_member_id','category'],'pn_recipient_category');
    if(!in_array('pn_email_retry',$indexes))$t->index(['email_status','next_attempt_at'],'pn_email_retry');
   });return;
  }
  Schema::create('partner_notifications',function(Blueprint $t){
  $t->id();$t->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();
  $t->foreignId('partner_team_member_id')->nullable()->constrained('partner_team_members')->cascadeOnDelete();
  $t->string('event_key',120);$t->string('recipient_key',40);$t->unique(['event_key','recipient_key']);
  $t->string('category',20);$t->text('content');$t->text('email');$t->timestamp('read_at')->nullable();
  $t->string('email_status',20)->default('pending');$t->unsignedTinyInteger('attempts')->default(0);$t->timestamp('next_attempt_at')->nullable();$t->timestamp('sent_at')->nullable();$t->timestamps();
  $t->index(['partner_application_id','partner_team_member_id','category'],'pn_recipient_category');$t->index(['email_status','next_attempt_at'],'pn_email_retry');
 });}
 public function down(): void {Schema::dropIfExists('partner_notifications');}
};
