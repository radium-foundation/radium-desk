<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();
            $table->string('ended_reason', 32)->nullable();
            $table->unsignedInteger('session_duration_seconds')->nullable();
            $table->unsignedInteger('active_duration_seconds')->default(0);
            $table->unsignedInteger('idle_duration_seconds')->default(0);
            $table->unsignedInteger('lunch_duration_seconds')->default(0);
            $table->unsignedInteger('break_duration_seconds')->default(0);
            $table->unsignedInteger('extra_idle_duration_seconds')->default(0);
            $table->unsignedInteger('overtime_seconds')->default(0);
            $table->unsignedInteger('break_allowance_seconds')->default(0);
            $table->unsignedSmallInteger('expected_working_minutes')->nullable();
            $table->boolean('on_time_login')->nullable();
            $table->unsignedInteger('cases_handled_count')->default(0);
            $table->unsignedInteger('communication_events_count')->default(0);
            $table->unsignedInteger('resolution_events_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'work_date']);
            $table->index(['user_id', 'logout_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
