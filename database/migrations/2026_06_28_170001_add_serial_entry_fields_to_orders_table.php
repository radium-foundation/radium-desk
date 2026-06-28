<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('serial_entered_at')->nullable()->after('serial_number');
            $table->foreignId('serial_entered_by_user_id')
                ->nullable()
                ->after('serial_entered_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_entered_by_user_id');
            $table->dropColumn('serial_entered_at');
        });
    }
};
