<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->timestamp('last_order_viewed_at')
                ->nullable()
                ->after('last_business_action');
            $table->timestamp('last_incident_viewed_at')
                ->nullable()
                ->after('last_order_viewed_at');
            $table->timestamp('last_business_action_at')
                ->nullable()
                ->after('last_incident_viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'last_order_viewed_at',
                'last_incident_viewed_at',
                'last_business_action_at',
            ]);
        });
    }
};
