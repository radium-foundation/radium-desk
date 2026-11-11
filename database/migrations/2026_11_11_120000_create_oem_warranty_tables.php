<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oem_warranty_lookup_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('serial_number', 100);
            $table->string('status', 32);
            $table->string('handoff_token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('draft_submitted_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('draft_payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'provider', 'status']);
        });

        Schema::create('oem_warranty_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('oem_warranty_lookup_session_id')->nullable()->constrained('oem_warranty_lookup_sessions')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('serial_number', 100);
            $table->string('outcome', 32);
            $table->string('capture_method', 32);
            $table->foreignId('verified_by_user_id')->constrained('users');
            $table->timestamp('checked_at');
            $table->date('warranty_expiry_date')->nullable();
            $table->date('rd_expiry_date')->nullable();
            $table->string('mantratec_model')->nullable();
            $table->string('morpho_order_number')->nullable();
            $table->date('morpho_whitelisting_date')->nullable();
            $table->date('morpho_expiry_date')->nullable();
            $table->string('morpho_validity_years')->nullable();
            $table->json('provider_payload')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['order_id', 'provider', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oem_warranty_checks');
        Schema::dropIfExists('oem_warranty_lookup_sessions');
    }
};
