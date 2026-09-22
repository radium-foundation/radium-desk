<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_quotes', function (Blueprint $table): void {
            $table->json('billing_address_structured')->nullable()->after('billing_address');
            $table->string('payment_reference', 128)->nullable()->after('place_of_supply_state');
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->json('billing_address_structured')->nullable()->after('billing_address');
            $table->string('payment_reference', 128)->nullable()->after('place_of_supply_state');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropColumn(['billing_address_structured', 'payment_reference']);
        });

        Schema::table('service_quotes', function (Blueprint $table): void {
            $table->dropColumn(['billing_address_structured', 'payment_reference']);
        });
    }
};
