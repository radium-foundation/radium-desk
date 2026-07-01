<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_waiting_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('waiting_reason');
            $table->timestamp('started_at');
            $table->boolean('sla_paused')->default(false);
            $table->string('reminder_policy_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['incident_id', 'cleared_at']);
            $table->index('waiting_reason');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX incident_waiting_states_one_active_per_incident '
                .'ON incident_waiting_states (incident_id) WHERE cleared_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_waiting_states');
    }
};
