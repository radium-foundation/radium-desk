<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_invoice_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statutory_invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->string('idempotency_key', 160);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('irn_action')->nullable();
            $table->json('inventory_action')->nullable();
            $table->json('credit_note_action')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique('statutory_invoice_id');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_invoice_cancellations');
    }
};
