<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiting_state_id')->constrained('incident_waiting_states')->cascadeOnDelete();
            $table->string('policy_key');
            $table->unsignedInteger('schedule_step');
            $table->string('action_type');
            $table->string('action_key');
            $table->string('channel')->nullable();
            $table->string('status');
            $table->string('idempotency_key')->unique();
            $table->string('external_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['waiting_state_id', 'status']);
            $table->index(['policy_key', 'schedule_step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
