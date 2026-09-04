<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_bank_account_upi_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_bank_account_id')
                ->constrained('finance_bank_accounts')
                ->cascadeOnDelete();
            $table->string('vpa', 128);
            $table->string('payee_name', 160);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique('finance_bank_account_id', 'fba_upi_profiles_account_unique');
            $table->index(['is_enabled', 'finance_bank_account_id'], 'fba_upi_profiles_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_bank_account_upi_profiles');
    }
};
