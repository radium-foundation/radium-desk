<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_intelligence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('version', 32);
            $table->unsignedTinyInteger('outcome_score');
            $table->unsignedTinyInteger('reach_score');
            $table->unsignedTinyInteger('contribution_score');
            $table->unsignedTinyInteger('commitment_score');
            $table->unsignedTinyInteger('quality_score');
            $table->decimal('composite_score', 6, 2);
            $table->json('breakdown');
            $table->json('inputs');
            $table->json('explanations');
            $table->json('feature_flags');
            $table->unsignedInteger('calculation_duration_ms')->default(0);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['user_id', 'snapshot_date'], 'pi_snapshots_user_date_unique');
            $table->index(['snapshot_date', 'composite_score'], 'pi_snapshots_date_composite_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_intelligence_snapshots');
    }
};
