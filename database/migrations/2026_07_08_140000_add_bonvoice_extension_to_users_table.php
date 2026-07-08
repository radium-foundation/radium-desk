<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('bonvoice_extension', 50)->nullable()->after('telegram_notifications_enabled');
            $table->index('bonvoice_extension');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['bonvoice_extension']);
            $table->dropColumn('bonvoice_extension');
        });
    }
};
