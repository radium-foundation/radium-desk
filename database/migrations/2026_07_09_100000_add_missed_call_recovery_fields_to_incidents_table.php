<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('recovery_phone', 20)->nullable()->after('high_priority');
            $table->unsignedSmallInteger('missed_call_attempt_count')->default(0)->after('recovery_phone');
            $table->timestamp('last_missed_at')->nullable()->after('missed_call_attempt_count');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['recovery_phone', 'missed_call_attempt_count', 'last_missed_at']);
        });
    }
};
