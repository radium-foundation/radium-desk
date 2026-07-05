<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('work_start_time');
            $table->time('work_end_time');
            $table->time('lunch_start_time')->nullable();
            $table->time('lunch_end_time')->nullable();
            $table->unsignedTinyInteger('short_break_count')->default(0);
            $table->unsignedSmallInteger('short_break_minutes')->default(10);
            $table->json('weekly_off_days')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('company_holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('team_member_work_schedules');
    }
};
