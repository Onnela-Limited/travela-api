<?php

use App\Models\PartnerApplication;
use App\Services\PartnerReferralService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->unique()->constrained('partner_applications')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->timestamps();
        });
        PartnerApplication::where('status', 'approved')->chunkById(100, function ($applications) {
            foreach ($applications as $application) app(PartnerReferralService::class)->ensureFor($application);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_referrals');
    }
};
