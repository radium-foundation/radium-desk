<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->json('courier_options_snapshot')->nullable();
            $table->string('courier_options_fingerprint', 64)->nullable();
            $table->timestamp('courier_options_fetched_at')->nullable();
            $table->timestamp('courier_options_expires_at')->nullable();
            $table->string('selected_courier_id', 64)->nullable();
            $table->string('selected_courier_name', 128)->nullable();
            $table->timestamp('selected_courier_at')->nullable();
            $table->foreignId('selected_courier_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_courier_by_user_id');
            $table->dropColumn([
                'courier_options_snapshot',
                'courier_options_fingerprint',
                'courier_options_fetched_at',
                'courier_options_expires_at',
                'selected_courier_id',
                'selected_courier_name',
                'selected_courier_at',
            ]);
        });
    }
};
