<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_products', 'uqc')) {
            Schema::table('inventory_products', function (Blueprint $table) {
                $table->string('uqc', 8)->nullable()->after('hsn_code');
            });
        }

        if (! Schema::hasColumn('commerce_order_items', 'uqc')) {
            Schema::table('commerce_order_items', function (Blueprint $table) {
                $table->string('uqc', 8)->nullable()->after('hsn_sac');
            });
        }

        if (! Schema::hasColumn('statutory_invoice_items', 'uqc')) {
            Schema::table('statutory_invoice_items', function (Blueprint $table) {
                $table->string('uqc', 8)->nullable()->after('hsn_sac');
            });
        }

        if (! Schema::hasColumn('inventory_sales', 'billing_address_structured')) {
            Schema::table('inventory_sales', function (Blueprint $table) {
                $table->json('billing_address_structured')->nullable()->after('billing_address');
            });
        }

        if (! Schema::hasColumn('statutory_invoices', 'billing_address_structured')) {
            Schema::table('statutory_invoices', function (Blueprint $table) {
                $table->json('billing_address_structured')->nullable()->after('billing_address');
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn('uqc');
        });

        Schema::table('commerce_order_items', function (Blueprint $table) {
            $table->dropColumn('uqc');
        });

        Schema::table('statutory_invoice_items', function (Blueprint $table) {
            $table->dropColumn('uqc');
        });

        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropColumn('billing_address_structured');
        });

        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->dropColumn('billing_address_structured');
        });
    }
};
