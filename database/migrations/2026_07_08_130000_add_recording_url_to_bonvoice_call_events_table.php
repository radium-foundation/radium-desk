<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonvoice_call_events', function (Blueprint $table) {
            $table->string('recording_url')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('bonvoice_call_events', function (Blueprint $table) {
            $table->dropColumn('recording_url');
        });
    }
};
