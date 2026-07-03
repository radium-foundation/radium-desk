<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'radiumbox_sync_status')) {
            return;
        }

        DB::table('orders')
            ->whereNull('radiumbox_sync_status')
            ->update(['radiumbox_sync_status' => 'NOT_SYNCED']);

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('radiumbox_sync_status', 20)
                ->default('NOT_SYNCED')
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'radiumbox_sync_status')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('radiumbox_sync_status', 20)->nullable()->default(null)->change();
        });
    }
};
