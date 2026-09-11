<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_customers', 'finance_party_id')) {
                $table->foreignId('finance_party_id')
                    ->nullable()
                    ->after('gstin')
                    ->constrained('finance_parties')
                    ->nullOnDelete();
            }
        });

        Schema::table('inventory_sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_sales', 'customer_type')) {
                $table->string('customer_type', 8)->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('inventory_sales', 'snapshot_buyer_name')) {
                $table->string('snapshot_buyer_name', 160)->nullable()->after('customer_type');
            }
            if (! Schema::hasColumn('inventory_sales', 'snapshot_buyer_phone')) {
                $table->string('snapshot_buyer_phone', 32)->nullable()->after('snapshot_buyer_name');
            }
            if (! Schema::hasColumn('inventory_sales', 'snapshot_buyer_email')) {
                $table->string('snapshot_buyer_email', 160)->nullable()->after('snapshot_buyer_phone');
            }
            if (! Schema::hasColumn('inventory_sales', 'billing_state')) {
                $table->string('billing_state', 64)->nullable()->after('billing_address');
            }
            if (! Schema::hasColumn('inventory_sales', 'billing_city')) {
                $table->string('billing_city', 120)->nullable()->after('billing_state');
            }
            if (! Schema::hasColumn('inventory_sales', 'billing_postal_code')) {
                $table->string('billing_postal_code', 16)->nullable()->after('billing_city');
            }
            if (! Schema::hasColumn('inventory_sales', 'finance_party_id')) {
                $table->foreignId('finance_party_id')
                    ->nullable()
                    ->after('billing_postal_code')
                    ->constrained('finance_parties')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('inventory_sales', 'finance_party_gst_registration_id')) {
                $table->foreignId('finance_party_gst_registration_id')
                    ->nullable()
                    ->after('finance_party_id')
                    ->constrained('finance_party_gst_registrations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('inventory_sales', 'place_of_supply_source')) {
                $table->string('place_of_supply_source', 64)->nullable()->after('place_of_supply_state');
            }
            if (! Schema::hasColumn('inventory_sales', 'buyer_pan')) {
                $table->string('buyer_pan', 16)->nullable()->after('buyer_gstin');
            }
        });

        Schema::create('statutory_invoice_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('statutory_invoices')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('destination', 255);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->string('status', 16);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_invoice_dispatches');

        Schema::table('inventory_sales', function (Blueprint $table): void {
            $columns = [
                'customer_type',
                'snapshot_buyer_name',
                'snapshot_buyer_phone',
                'snapshot_buyer_email',
                'billing_state',
                'billing_city',
                'billing_postal_code',
                'finance_party_id',
                'finance_party_gst_registration_id',
                'place_of_supply_source',
                'buyer_pan',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('inventory_sales', $column)) {
                    if (in_array($column, ['finance_party_id', 'finance_party_gst_registration_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('inventory_customers', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_customers', 'finance_party_id')) {
                $table->dropConstrainedForeignId('finance_party_id');
            }
        });
    }
};
