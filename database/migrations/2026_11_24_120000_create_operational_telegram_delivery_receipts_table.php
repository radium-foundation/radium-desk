<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_telegram_delivery_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_kind', 64);
            $table->date('report_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_message_id', 64)->nullable();
            $table->timestamp('delivered_at');
            $table->timestamps();

            $table->unique(['delivery_kind', 'report_date', 'user_id'], 'operational_telegram_delivery_unique');
            $table->index(['delivery_kind', 'report_date'], 'op_tg_delivery_kind_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_telegram_delivery_receipts');
    }
};
