<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statutory_invoices')) {
            return;
        }

        Schema::table('statutory_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('statutory_invoices', 'place_of_supply_state_code')) {
                $table->string('place_of_supply_state_code', 2)->nullable()->after('place_of_supply_state');
            }
            if (! Schema::hasColumn('statutory_invoices', 'place_of_supply_source')) {
                $table->string('place_of_supply_source', 64)->nullable()->after('place_of_supply_state_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('statutory_invoices')) {
            return;
        }

        Schema::table('statutory_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('statutory_invoices', 'place_of_supply_source')) {
                $table->dropColumn('place_of_supply_source');
            }
            if (Schema::hasColumn('statutory_invoices', 'place_of_supply_state_code')) {
                $table->dropColumn('place_of_supply_state_code');
            }
        });
    }
};
