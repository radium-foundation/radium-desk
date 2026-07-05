<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('missing_serial_automation_status', 32)->nullable()->after('serial_entered_by_user_id');
            $table->timestamp('missing_serial_first_requested_at')->nullable()->after('missing_serial_automation_status');
            $table->timestamp('missing_serial_last_contacted_at')->nullable()->after('missing_serial_first_requested_at');
            $table->timestamp('missing_serial_escalated_at')->nullable()->after('missing_serial_last_contacted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'missing_serial_automation_status',
                'missing_serial_first_requested_at',
                'missing_serial_last_contacted_at',
                'missing_serial_escalated_at',
            ]);
        });
    }
};
