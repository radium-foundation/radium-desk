<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashfree_webhook_logs', function (Blueprint $table) {
            $table->string('source_ip')->nullable()->after('received_at');
            $table->text('user_agent')->nullable()->after('source_ip');
            $table->string('processing_status')->default('received')->after('user_agent');
            $table->text('processing_error')->nullable()->after('processing_status');
            $table->timestamp('processed_at')->nullable()->after('processing_error');

            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('cashfree_webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['processing_status']);
            $table->dropColumn([
                'source_ip',
                'user_agent',
                'processing_status',
                'processing_error',
                'processed_at',
            ]);
        });
    }
};
