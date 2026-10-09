<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_wallet_balance_migrations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('source_wallet_attempt')->default(0)->after('source_users_wallet_id');
        });

        Schema::table('central_wallet_balance_migrations', function (Blueprint $table): void {
            $table->dropUnique('cw_balance_migrations_source_row_uq');
            $table->unique(
                ['source_site_code', 'source_users_wallet_id', 'source_wallet_attempt'],
                'cw_balance_migrations_source_row_attempt_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('central_wallet_balance_migrations', function (Blueprint $table): void {
            $table->dropUnique('cw_balance_migrations_source_row_attempt_uq');
            $table->unique(
                ['source_site_code', 'source_users_wallet_id'],
                'cw_balance_migrations_source_row_uq',
            );
            $table->dropColumn('source_wallet_attempt');
        });
    }
};
