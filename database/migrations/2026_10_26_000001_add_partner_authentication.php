<?php

use App\Models\PartnerApplication;
use App\Support\PartnerIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->char('email_hash', 64)->nullable()->index();
        });
        PartnerApplication::query()->chunkById(100, function ($applications) {
            foreach ($applications as $application) {
                $email = $application->application_payload['profile']['email'] ?? null;
                if (is_string($email) && $email !== '') {
                    DB::table('partner_applications')->where('id', $application->id)
                        ->update(['email_hash' => PartnerIdentity::emailHash($email)]);
                }
            }
        });
        Schema::create('partner_login_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('email_hash', 64)->unique();
            $table->foreignId('partner_application_id')->nullable()->constrained('partner_applications')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->constrained('partner_applications')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_sessions');
        Schema::dropIfExists('partner_login_challenges');
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropIndex(['email_hash']);
            $table->dropColumn('email_hash');
        });
    }
};
