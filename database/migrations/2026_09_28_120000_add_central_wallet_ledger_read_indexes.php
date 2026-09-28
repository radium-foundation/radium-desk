<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            // Caller-scoped reconciliation sweeps with keyset pagination on (posted_at, id).
            $table->index(['source_system', 'posted_at', 'id'], 'cw_ledger_source_posted_idx');

            // Wallet-scoped reconciliation lists with keyset pagination on (posted_at, id).
            $table->index(['central_wallet_id', 'posted_at', 'id'], 'cw_ledger_wallet_posted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('cw_ledger_source_posted_idx');
            $table->dropIndex('cw_ledger_wallet_posted_idx');
        });
    }
};
