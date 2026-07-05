<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ira_operational_memory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->json('operations');
            $table->json('team');
            $table->json('performance');
            $table->timestamps();
        });

        Schema::create('ira_insight_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('insight_key', 128);
            $table->string('insight_type', 32);
            $table->json('insight_payload');
            $table->string('response', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('responded_at');
            $table->timestamps();

            $table->index(['insight_key', 'responded_at']);
            $table->index(['insight_type', 'response']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ira_insight_feedback');
        Schema::dropIfExists('ira_operational_memory_snapshots');
    }
};
