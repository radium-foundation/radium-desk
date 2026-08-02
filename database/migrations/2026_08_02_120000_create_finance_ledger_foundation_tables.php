<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::table('finance_cash_accounts', function (Blueprint $table) {
            $table->foreignId('gl_account_id')
                ->nullable()
                ->after('name')
                ->constrained('finance_accounts')
                ->nullOnDelete();
        });

        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->foreignId('gl_account_id')
                ->nullable()
                ->after('last_four')
                ->constrained('finance_accounts')
                ->nullOnDelete();
        });

        Schema::table('finance_expense_categories', function (Blueprint $table) {
            $table->foreignId('default_gl_account_id')
                ->nullable()
                ->after('name')
                ->constrained('finance_accounts')
                ->nullOnDelete();
        });

        Schema::create('finance_journals', function (Blueprint $table) {
            $table->id();
            $table->string('journal_no')->unique();
            $table->date('entry_date');
            $table->string('memo');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
        });

        Schema::create('finance_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('finance_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->index(['account_id', 'journal_id']);
        });

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->foreignId('journal_id')
                ->nullable()
                ->after('posted_by')
                ->constrained('finance_journals')
                ->nullOnDelete();
        });

        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::dropIfExists('finance_journal_lines');
        Schema::dropIfExists('finance_journals');
        Schema::dropIfExists('finance_settings');

        Schema::table('finance_expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_gl_account_id');
        });

        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });

        Schema::table('finance_cash_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });

        Schema::dropIfExists('finance_accounts');
    }
};
