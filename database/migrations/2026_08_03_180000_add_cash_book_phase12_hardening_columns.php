<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_book_entries', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('journal_id');
            $table->boolean('is_historical')->default(false)->after('locked_at');
            $table->string('backdate_reason', 500)->nullable()->after('is_historical');
            $table->string('historical_reason', 500)->nullable()->after('backdate_reason');
            $table->timestamp('imported_at')->nullable()->after('historical_reason');

            $table->index('is_historical');
            $table->index('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_book_entries', function (Blueprint $table) {
            $table->dropIndex(['is_historical']);
            $table->dropIndex(['locked_at']);
            $table->dropColumn([
                'locked_at',
                'is_historical',
                'backdate_reason',
                'historical_reason',
                'imported_at',
            ]);
        });
    }
};
