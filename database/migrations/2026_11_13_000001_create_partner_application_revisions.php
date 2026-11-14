<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('partner_application_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('from_status');
            $table->unsignedInteger('from_review_version');
            $table->longText('previous_details');
            $table->longText('previous_documents');
            $table->timestamp('created_at');
            $table->unique(['partner_application_id','request_key'], 'partner_app_revision_request_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('partner_application_revisions'); }
};
