<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->json('parcel_snapshot')->nullable();
            $table->string('shipping_country_overlay', 64)->nullable();
            $table->timestamp('shipping_country_overlay_at')->nullable();
            $table->foreignId('shipping_country_overlay_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('shipping_country_overlay_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_country_overlay_by_user_id');
            $table->dropColumn([
                'parcel_snapshot',
                'shipping_country_overlay',
                'shipping_country_overlay_at',
                'shipping_country_overlay_context',
            ]);
        });
    }
};
