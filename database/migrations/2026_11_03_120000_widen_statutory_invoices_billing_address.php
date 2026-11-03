<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_invoices', function (Blueprint $table) {
            $table->text('billing_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally omitted: narrowing to VARCHAR(255) is unsafe once long addresses exist.
    }
};
