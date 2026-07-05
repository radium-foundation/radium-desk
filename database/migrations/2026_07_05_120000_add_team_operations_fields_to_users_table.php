<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('availability_status', 32)->default('offline')->after('is_active');
            $table->timestamp('availability_updated_at')->nullable()->after('availability_status');
            $table->date('leave_start_date')->nullable()->after('availability_updated_at');
            $table->date('leave_end_date')->nullable()->after('leave_start_date');
            $table->timestamp('last_active_at')->nullable()->after('leave_end_date');
            $table->timestamp('last_case_action_at')->nullable()->after('last_active_at');
            $table->timestamp('last_customer_communication_at')->nullable()->after('last_case_action_at');
            $table->timestamp('last_status_change_at')->nullable()->after('last_customer_communication_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'availability_status',
                'availability_updated_at',
                'leave_start_date',
                'leave_end_date',
                'last_active_at',
                'last_case_action_at',
                'last_customer_communication_at',
                'last_status_change_at',
            ]);
        });
    }
};
