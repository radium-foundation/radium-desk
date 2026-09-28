<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->string('shipping_method', 24)->nullable()->after('ready_for_pickup_at');
            $table->string('external_courier_code', 40)->nullable()->after('shipping_method');
            $table->text('external_notes')->nullable()->after('external_courier_code');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_url', 1024)->nullable()->after('manifest_generated_at');
            $table->string('label_disk', 32)->nullable()->after('tracking_url');
            $table->string('label_path', 512)->nullable()->after('label_disk');
            $table->string('label_source', 24)->nullable()->after('label_path');
            $table->string('manifest_disk', 32)->nullable()->after('label_source');
            $table->string('manifest_path', 512)->nullable()->after('manifest_disk');
            $table->timestamp('dispatched_at')->nullable()->after('manifest_path');
            $table->foreignId('dispatched_by_user_id')->nullable()->after('dispatched_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatched_by_user_id');
            $table->dropColumn([
                'tracking_url',
                'label_disk',
                'label_path',
                'label_source',
                'manifest_disk',
                'manifest_path',
                'dispatched_at',
            ]);
        });

        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_method',
                'external_courier_code',
                'external_notes',
            ]);
        });
    }
};
