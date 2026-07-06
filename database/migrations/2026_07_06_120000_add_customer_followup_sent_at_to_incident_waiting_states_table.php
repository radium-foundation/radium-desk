<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_waiting_states', function (Blueprint $table) {
            $table->timestamp('customer_followup_sent_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_waiting_states', function (Blueprint $table) {
            $table->dropColumn('customer_followup_sent_at');
        });
    }
};
