<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_email_messages', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->after('incident_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->index('order_id', 'iem_order_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
