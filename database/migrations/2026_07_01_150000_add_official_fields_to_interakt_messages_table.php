<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interakt_messages', function (Blueprint $table) {
            $table->string('template_language')->nullable()->after('template_name');
            $table->text('channel_failure_reason')->nullable()->after('delivery_status');
            $table->string('channel_error_code', 50)->nullable()->after('channel_failure_reason');
            $table->string('callback_data')->nullable()->after('channel_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('interakt_messages', function (Blueprint $table) {
            $table->dropColumn([
                'template_language',
                'channel_failure_reason',
                'channel_error_code',
                'callback_data',
            ]);
        });
    }
};
