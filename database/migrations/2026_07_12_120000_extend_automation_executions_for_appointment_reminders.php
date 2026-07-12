<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_executions', function (Blueprint $table) {
            $table->dropForeign(['waiting_state_id']);
        });

        Schema::table('automation_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('waiting_state_id')->nullable()->change();
            $table->foreign('waiting_state_id')
                ->references('id')
                ->on('incident_waiting_states')
                ->cascadeOnDelete();

            $table->foreignId('support_appointment_id')
                ->nullable()
                ->after('waiting_state_id')
                ->constrained('support_appointments')
                ->nullOnDelete();

            $table->index(['support_appointment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_executions', function (Blueprint $table) {
            $table->dropForeign(['support_appointment_id']);
            $table->dropIndex(['support_appointment_id', 'status']);
            $table->dropColumn('support_appointment_id');
        });

        Schema::table('automation_executions', function (Blueprint $table) {
            $table->dropForeign(['waiting_state_id']);
        });

        Schema::table('automation_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('waiting_state_id')->nullable(false)->change();
            $table->foreign('waiting_state_id')
                ->references('id')
                ->on('incident_waiting_states')
                ->cascadeOnDelete();
        });
    }
};
