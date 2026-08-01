<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_recognition_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('day_context', 32);
            $table->string('status', 32);
            $table->unsignedInteger('login_seconds')->nullable();
            $table->unsignedInteger('productive_seconds')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->decimal('ira_score', 8, 2)->nullable();
            $table->string('ira_recommendation', 32);
            $table->text('ira_rationale')->nullable();
            $table->string('decision', 32)->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('department_pack', 64);
            $table->unsignedSmallInteger('source_version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['status', 'work_date']);
            $table->index(['day_context', 'work_date']);
            $table->index(['decision', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_recognition_reviews');
    }
};
