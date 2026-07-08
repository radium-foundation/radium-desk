<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonvoice_call_events', function (Blueprint $table): void {
            $table->index('started_at');
            $table->index('status');
            $table->index('destination_number');
        });
    }

    public function down(): void
    {
        Schema::table('bonvoice_call_events', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropIndex(['status']);
            $table->dropIndex(['destination_number']);
        });
    }
};
