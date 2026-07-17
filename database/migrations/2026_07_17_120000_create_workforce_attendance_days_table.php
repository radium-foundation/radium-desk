<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 32);
            $table->string('calendar_status', 32);
            $table->boolean('is_working_day')->default(false);
            $table->boolean('is_company_holiday')->default(false);
            $table->boolean('is_on_leave')->default(false);
            $table->boolean('has_schedule')->default(false);
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_logout_at')->nullable();
            $table->boolean('on_time_login')->nullable();
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->unsignedSmallInteger('session_count')->default(0);
            $table->unsignedInteger('session_duration_seconds')->default(0);
            $table->unsignedInteger('active_duration_seconds')->default(0);
            $table->unsignedInteger('idle_duration_seconds')->default(0);
            $table->unsignedInteger('lunch_duration_seconds')->default(0);
            $table->unsignedInteger('break_duration_seconds')->default(0);
            $table->unsignedInteger('extra_idle_duration_seconds')->default(0);
            $table->unsignedInteger('overtime_seconds')->default(0);
            $table->unsignedSmallInteger('away_timeout_count')->default(0);
            $table->unsignedSmallInteger('manual_logout_count')->default(0);
            $table->unsignedSmallInteger('expected_working_minutes')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('computed_at');
            $table->unsignedSmallInteger('source_version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_attendance_days');
    }
};
