<?php

namespace Tests\Feature\RadiumBoxRead\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RadiumBoxReadSqliteSchema
{
    public static function migrate(string $connection = 'radiumbox_read'): void
    {
        Schema::connection($connection)->create('orders', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('ordercode')->nullable();
            $table->string('ordertype')->nullable();
            $table->string('rdservice_order_id')->nullable();
            $table->string('invoicecode')->nullable();
            $table->string('userid')->nullable();
            $table->string('gst_no')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('status')->nullable();
            $table->string('orderdate')->nullable();
            $table->string('branch')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connection)->create('order_rdservice', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('rdorderid')->nullable();
            $table->integer('userid')->nullable();
            $table->string('gst_no')->nullable();
            $table->string('product_name')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('status')->nullable();
            $table->string('website')->nullable();
            $table->string('amc_service_name')->nullable();
            $table->string('rd_service_name')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connection)->create('invoice', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('orderid')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('branch')->nullable();
            $table->string('service_type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connection)->create('order_details', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('orderid')->nullable();
            $table->string('product_name')->nullable();
            $table->string('productid')->nullable();
            $table->string('invoicecode')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connection)->create('order_history', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('orderid');
            $table->string('updated_by')->nullable();
            $table->string('status')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('gst_no')->nullable();
            $table->string('company_name')->nullable();
        });
    }
}
