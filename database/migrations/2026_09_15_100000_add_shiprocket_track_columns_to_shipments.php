<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'provider_track_status')) {
                $table->string('provider_track_status', 64)->nullable()->after('last_reconciled_at');
            }
            if (! Schema::hasColumn('shipments', 'provider_track_normalized')) {
                $table->string('provider_track_normalized', 32)->nullable()->after('provider_track_status');
            }
            if (! Schema::hasColumn('shipments', 'provider_tracked_at')) {
                $table->timestamp('provider_tracked_at')->nullable()->after('provider_track_normalized');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach (['provider_tracked_at', 'provider_track_normalized', 'provider_track_status'] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
