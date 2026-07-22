<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_repair_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('repair_key', 100);
            $table->string('status', 32);
            $table->string('phase', 32)->nullable();
            $table->json('options')->nullable();
            $table->string('environment', 32)->nullable();
            $table->string('initiated_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('checkpoint')->nullable();
            $table->json('counts')->nullable();
            $table->json('report_paths')->nullable();
            $table->uuid('parent_batch_uuid')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['repair_key', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('system_repair_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('system_repair_batches')->cascadeOnDelete();
            $table->string('repair_key', 100);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_key')->nullable();
            $table->string('related_type')->nullable();
            // 0 = no related subject (avoids NULL unique issues across drivers)
            $table->unsignedBigInteger('related_id')->default(0);
            $table->string('action', 64);
            $table->string('category', 64)->nullable();
            $table->string('outcome', 32);
            $table->string('skip_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'outcome']);
            $table->index(['repair_key', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->unique(
                ['batch_id', 'subject_type', 'subject_id', 'related_id'],
                'system_repair_items_batch_subject_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_repair_items');
        Schema::dropIfExists('system_repair_batches');
    }
};
