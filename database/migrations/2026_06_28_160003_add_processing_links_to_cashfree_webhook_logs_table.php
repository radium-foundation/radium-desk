<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashfree_webhook_logs', function (Blueprint $table) {
            $table->string('cf_payment_id', 100)->nullable()->after('webhook_version');
            $table->foreignId('incident_id')->nullable()->after('processed_at')->constrained('incidents')->nullOnDelete();

            $table->index('cf_payment_id');
            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('cashfree_webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['cf_payment_id']);
            $table->dropIndex(['incident_id']);
            $table->dropConstrainedForeignId('incident_id');
            $table->dropColumn('cf_payment_id');
        });
    }
};
