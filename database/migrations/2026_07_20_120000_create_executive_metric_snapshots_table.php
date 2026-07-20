<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 64);
            $table->timestamp('snapshot_time');
            $table->decimal('metric_value', 14, 2);
            $table->string('status', 32)->nullable();
            $table->string('granularity', 16)->default('hourly');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['metric_key', 'snapshot_time', 'granularity'], 'exec_metric_snap_unique');
            $table->index(['granularity', 'snapshot_time'], 'exec_metric_snap_granularity_time');
            $table->index(['metric_key', 'granularity', 'snapshot_time'], 'exec_metric_snap_key_gran_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_metric_snapshots');
    }
};
