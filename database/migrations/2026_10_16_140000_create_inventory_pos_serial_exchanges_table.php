<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_pos_serial_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignId('sale_serial_id')->constrained('inventory_sale_serials')->cascadeOnDelete();
            $table->foreignId('from_serial_id')->constrained('inventory_serials');
            $table->foreignId('to_serial_id')->constrained('inventory_serials');
            $table->text('reason');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['sale_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_pos_serial_exchanges');
    }
};
