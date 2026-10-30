<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('commerce_orders', 'statutory_auto_issue_approved_at')) {
                $table->timestamp('statutory_auto_issue_approved_at')->nullable()->after('statutory_invoice_id');
            }
            if (! Schema::hasColumn('commerce_orders', 'statutory_auto_issue_approved_by')) {
                $table->foreignId('statutory_auto_issue_approved_by')
                    ->nullable()
                    ->after('statutory_auto_issue_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            if (Schema::hasColumn('commerce_orders', 'statutory_auto_issue_approved_by')) {
                $table->dropConstrainedForeignId('statutory_auto_issue_approved_by');
            }
            if (Schema::hasColumn('commerce_orders', 'statutory_auto_issue_approved_at')) {
                $table->dropColumn('statutory_auto_issue_approved_at');
            }
        });
    }
};
