<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_invoice_cancellations', function (Blueprint $table) {
            $table->string('status', 48)->default('completed')->after('reason');
            $table->uuid('correlation_id')->nullable()->after('status');
            $table->string('provider_reference', 128)->nullable()->after('correlation_id');
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('provider_reference');
            $table->text('last_error')->nullable()->after('attempt_count');
            $table->timestamp('irn_confirmed_at')->nullable()->after('last_error');
        });

        Schema::table('statutory_invoice_cancellations', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->change();
        });

        DB::table('statutory_invoice_cancellations')->update([
            'status' => 'completed',
        ]);
    }

    public function down(): void
    {
        Schema::table('statutory_invoice_cancellations', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'correlation_id',
                'provider_reference',
                'attempt_count',
                'last_error',
                'irn_confirmed_at',
            ]);
        });
    }
};
