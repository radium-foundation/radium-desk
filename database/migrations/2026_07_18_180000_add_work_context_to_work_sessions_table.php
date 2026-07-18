<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->foreignId('current_order_id')
                ->nullable()
                ->after('resolution_events_count')
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('current_incident_id')
                ->nullable()
                ->after('current_order_id')
                ->constrained('incidents')
                ->nullOnDelete();
            $table->string('last_business_action', 64)
                ->nullable()
                ->after('current_incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_order_id');
            $table->dropConstrainedForeignId('current_incident_id');
            $table->dropColumn('last_business_action');
        });
    }
};
