<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('device_model_id')
                ->nullable()
                ->after('device_model')
                ->constrained('device_models')
                ->nullOnDelete();
            $table->timestamp('device_model_assigned_at')->nullable()->after('device_model_id');
            $table->foreignId('device_model_assigned_by_user_id')
                ->nullable()
                ->after('device_model_assigned_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_model_assigned_by_user_id');
            $table->dropColumn('device_model_assigned_at');
            $table->dropConstrainedForeignId('device_model_id');
        });
    }
};
