<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->string('origin', 32)->default('migration')->after('ended_reason');
            $table->boolean('is_attributable')->default(true)->after('origin');

            $table->index(['user_id', 'work_date', 'is_attributable']);
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'work_date', 'is_attributable']);
            $table->dropColumn(['origin', 'is_attributable']);
        });
    }
};
