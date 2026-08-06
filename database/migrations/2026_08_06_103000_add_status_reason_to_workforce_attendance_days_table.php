<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_attendance_days', function (Blueprint $table): void {
            $table->string('status_reason', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_attendance_days', function (Blueprint $table): void {
            $table->dropColumn('status_reason');
        });
    }
};
