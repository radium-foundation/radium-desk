<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('radiumbox_sync_status', 20)->nullable()->after('serial_entered_by_user_id');
            $table->timestamp('radiumbox_last_sync_at')->nullable()->after('radiumbox_sync_status');
            $table->text('radiumbox_last_sync_error')->nullable()->after('radiumbox_last_sync_at');
            $table->unsignedInteger('radiumbox_sync_attempts')->default(0)->after('radiumbox_last_sync_error');

            $table->index('radiumbox_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['radiumbox_sync_status']);
            $table->dropColumn([
                'radiumbox_sync_status',
                'radiumbox_last_sync_at',
                'radiumbox_last_sync_error',
                'radiumbox_sync_attempts',
            ]);
        });
    }
};
