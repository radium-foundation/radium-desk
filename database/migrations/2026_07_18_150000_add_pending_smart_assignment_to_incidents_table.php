<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->boolean('pending_smart_assignment')
                ->default(false)
                ->after('automation_pending_until');

            $table->index('pending_smart_assignment', 'incidents_pending_smart_assignment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('incidents_pending_smart_assignment_idx');
            $table->dropColumn('pending_smart_assignment');
        });
    }
};
