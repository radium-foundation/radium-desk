<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce at most one credit note per original statutory invoice.
 *
 * Only credit notes populate original_statutory_invoice_id; tax invoices keep NULL.
 * MySQL/SQLite UNIQUE allows multiple NULLs while rejecting duplicate non-null values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table): void {
            $table->unique(
                'original_statutory_invoice_id',
                'statutory_invoices_credit_note_original_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table): void {
            $table->dropUnique('statutory_invoices_credit_note_original_unique');
        });
    }
};
