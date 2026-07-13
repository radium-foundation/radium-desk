<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_models', function (Blueprint $table) {
            $table->string('buy_device_url', 500)->nullable()->after('driver_download_url');
            $table->string('buy_rd_service_url', 500)->nullable()->after('buy_device_url');
        });
    }

    public function down(): void
    {
        Schema::table('device_models', function (Blueprint $table) {
            $table->dropColumn(['buy_device_url', 'buy_rd_service_url']);
        });
    }
};
