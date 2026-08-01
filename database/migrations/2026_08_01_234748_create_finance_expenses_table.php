<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no')->unique();
            $table->date('expense_date');
            $table->foreignId('expense_category_id')->constrained('finance_expense_categories');
            $table->decimal('amount', 12, 2);
            $table->foreignId('payment_method_id')->constrained('finance_payment_methods');
            $table->foreignId('cash_account_id')->nullable()->constrained('finance_cash_accounts');
            $table->foreignId('bank_account_id')->nullable()->constrained('finance_bank_accounts');
            $table->text('description');
            $table->string('receipt_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['status', 'expense_date']);
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
