<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_short_attendance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 32);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_logout_at')->nullable();
            $table->unsignedSmallInteger('session_count')->default(0);
            $table->unsignedSmallInteger('away_timeout_count')->default(0);
            $table->boolean('had_auto_logout')->default(false);
            $table->string('shift_label', 64)->nullable();
            $table->string('department', 128)->nullable();
            $table->string('manager_name', 128)->nullable();
            $table->string('calculated_reason', 64)->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->string('previous_status', 32)->default('short_attendance');
            $table->string('decision', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['status', 'work_date']);
            $table->index(['decision', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_short_attendance_reviews');
    }
};
