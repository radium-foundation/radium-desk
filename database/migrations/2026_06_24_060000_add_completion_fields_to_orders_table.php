<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('transaction_id');
            $table->foreignId('transaction_assigned_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
        });

        DB::table('orders')
            ->whereNotNull('transaction_id')
            ->whereNull('completed_at')
            ->update([
                'completed_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_assigned_by');
            $table->dropColumn('completed_at');
        });
    }
};
