<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->unique(
                ['business_reference', 'entry_type'],
                'cw_ledger_business_ref_entry_type_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('cw_ledger_business_ref_entry_type_uq');
        });
    }
};
