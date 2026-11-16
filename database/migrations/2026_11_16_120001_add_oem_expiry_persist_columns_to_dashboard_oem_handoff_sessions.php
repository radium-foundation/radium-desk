<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->string('oem_expiry_persist_status', 32)->nullable()->after('rejection_reason');
            $table->string('oem_expiry_persist_detail', 255)->nullable()->after('oem_expiry_persist_status');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->dropColumn(['oem_expiry_persist_status', 'oem_expiry_persist_detail']);
        });
    }
};
