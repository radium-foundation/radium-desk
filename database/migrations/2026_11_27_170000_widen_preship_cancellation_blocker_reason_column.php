<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_shipment_cancellation_requests', function (Blueprint $table): void {
            $table->text('blocker_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pre_shipment_cancellation_requests', function (Blueprint $table): void {
            $table->string('blocker_reason', 64)->nullable()->change();
        });
    }
};
