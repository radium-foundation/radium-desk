<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_exchange_cases', function (Blueprint $table): void {
            $table->foreignId('refund_request_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('refund_requests')
                ->nullOnDelete();
            $table->foreignId('recorded_tax_statutory_invoice_id')
                ->nullable()
                ->after('refund_request_id')
                ->constrained('statutory_invoices')
                ->nullOnDelete();
            $table->string('recorded_tax_outcome_type', 32)->nullable()->after('recorded_tax_statutory_invoice_id');

            $table->unique('refund_request_id', 'return_case_refund_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('return_exchange_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_tax_statutory_invoice_id');
            $table->dropConstrainedForeignId('refund_request_id');
            $table->dropColumn('recorded_tax_outcome_type');
        });
    }
};
