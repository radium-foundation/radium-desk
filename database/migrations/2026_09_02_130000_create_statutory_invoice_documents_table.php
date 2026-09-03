<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->string('status', 24);
            $table->string('disk', 32)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('content_type', 80)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_invoice_documents');
    }
};
