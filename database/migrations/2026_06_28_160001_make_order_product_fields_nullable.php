<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['serial_number']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->change();
            $table->string('product_name')->nullable()->change();
            $table->string('device_model')->nullable()->change();
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['serial_number']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable(false)->change();
            $table->string('product_name')->nullable(false)->change();
            $table->string('device_model')->nullable(false)->change();
            $table->unique('serial_number');
        });
    }
};
