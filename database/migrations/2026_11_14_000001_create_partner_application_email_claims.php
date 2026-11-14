<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void {
        Schema::create('partner_application_email_claims', function (Blueprint $table) {
            $table->char('email_hash',64)->primary();$table->timestamp('created_at');
        });
        // Reserve existing emails without deleting or merging legacy applications.
        DB::table('partner_applications')->whereNotNull('email_hash')->select('id','email_hash')->chunkById(500,function($applications){
            $rows=$applications->map(fn($app)=>['email_hash'=>$app->email_hash,'created_at'=>now()])->all();
            if ($rows) DB::table('partner_application_email_claims')->insertOrIgnore($rows);
        });
    }
    public function down(): void { Schema::dropIfExists('partner_application_email_claims'); }
};
