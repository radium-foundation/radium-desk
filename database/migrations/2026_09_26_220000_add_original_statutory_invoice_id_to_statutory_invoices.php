<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table): void {
            $table->foreignId('original_statutory_invoice_id')
                ->nullable()
                ->after('support_order_id')
                ->constrained('statutory_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_statutory_invoice_id');
        });
    }
};
