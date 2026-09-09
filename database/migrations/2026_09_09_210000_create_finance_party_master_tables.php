<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_parties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('kind', 24);
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'legal_name']);
            $table->index('phone');
            $table->index('email');
            $table->index('trade_name');
        });

        Schema::create('finance_party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('role', 24);
            $table->string('vendor_code', 32)->nullable();
            $table->string('payment_terms')->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->string('preferred_payment_method')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'role']);
            $table->unique('vendor_code');
            $table->index('role');
        });

        Schema::create('finance_party_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('label', 64)->nullable();
            $table->string('kind', 24);
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('state');
            $table->string('state_code', 8)->nullable();
            $table->string('postal_code', 16);
            $table->string('country', 64)->default('India');
            $table->string('landmark')->nullable();
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['party_id', 'is_active']);
            $table->index(['party_id', 'is_default_billing']);
            $table->index(['party_id', 'is_default_shipping']);
        });

        Schema::create('finance_party_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['party_id', 'is_active']);
            $table->index(['party_id', 'is_primary']);
        });

        Schema::create('finance_party_gst_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('gstin', 15);
            $table->string('registered_name')->nullable();
            $table->string('state');
            $table->string('state_code', 2);
            $table->string('pan', 10)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['party_id', 'gstin']);
            $table->index('gstin');
            $table->index(['party_id', 'is_primary']);
        });

        Schema::create('finance_party_vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_holder_name');
            $table->text('account_number');
            $table->string('last_four', 4);
            $table->string('ifsc', 11);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['party_id', 'is_active']);
        });

        Schema::create('finance_party_legacy_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('finance_parties')->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('external_id', 64);
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_party_legacy_identities');
        Schema::dropIfExists('finance_party_vendor_bank_accounts');
        Schema::dropIfExists('finance_party_gst_registrations');
        Schema::dropIfExists('finance_party_contacts');
        Schema::dropIfExists('finance_party_addresses');
        Schema::dropIfExists('finance_party_roles');
        Schema::dropIfExists('finance_parties');
    }
};
