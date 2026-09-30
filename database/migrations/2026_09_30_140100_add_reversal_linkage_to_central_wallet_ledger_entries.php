<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('original_ledger_entry_id')->nullable()->after('reservation_id');

            $table->foreign('original_ledger_entry_id', 'cw_ledger_original_entry_fk')
                ->references('id')
                ->on('central_wallet_ledger_entries')
                ->nullOnDelete();

            $table->index('original_ledger_entry_id', 'cw_ledger_original_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropForeign('cw_ledger_original_entry_fk');
            $table->dropIndex('cw_ledger_original_entry_idx');
            $table->dropColumn('original_ledger_entry_id');
        });
    }
};
