<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('customer_name_locked_at')->nullable()->after('customer_name');
            $table->foreignId('customer_name_locked_by')
                ->nullable()
                ->after('customer_name_locked_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('customer_phone_locked_at')->nullable()->after('customer_phone');
            $table->foreignId('customer_phone_locked_by')
                ->nullable()
                ->after('customer_phone_locked_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('customer_email_locked_at')->nullable()->after('customer_email');
            $table->foreignId('customer_email_locked_by')
                ->nullable()
                ->after('customer_email_locked_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_email_locked_by');
            $table->dropColumn('customer_email_locked_at');
            $table->dropConstrainedForeignId('customer_phone_locked_by');
            $table->dropColumn('customer_phone_locked_at');
            $table->dropConstrainedForeignId('customer_name_locked_by');
            $table->dropColumn('customer_name_locked_at');
        });
    }
};
