<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of order/incident identity naming cleanup (BR-02 §15).
 *
 * Adds incidents.order_record_id (FK → orders.id) while keeping legacy
 * incidents.order_id dual-written. No destructive drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('order_record_id')
                ->nullable()
                ->after('order_id');
            $table->index('order_record_id');
        });

        DB::table('incidents')
            ->whereNull('order_record_id')
            ->update([
                'order_record_id' => DB::raw('order_id'),
            ]);

        $mismatched = (int) DB::table('incidents')
            ->where(function ($query): void {
                $query->whereNull('order_record_id')
                    ->orWhereColumn('order_record_id', '!=', 'order_id');
            })
            ->count();

        if ($mismatched > 0) {
            throw new RuntimeException(
                "Cannot enforce incidents.order_record_id: {$mismatched} row(s) still null or mismatched vs order_id."
            );
        }

        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('order_record_id')->nullable(false)->change();
            $table->foreign('order_record_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['order_record_id']);
            $table->dropIndex(['order_record_id']);
            $table->dropColumn('order_record_id');
        });
    }
};
