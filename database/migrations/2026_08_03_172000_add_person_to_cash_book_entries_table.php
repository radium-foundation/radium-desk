<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_book_entries', function (Blueprint $table) {
            $table->string('person', 255)->nullable()->after('category');
            $table->index('person');
        });
    }

    public function down(): void
    {
        Schema::table('cash_book_entries', function (Blueprint $table) {
            $table->dropIndex(['person']);
            $table->dropColumn('person');
        });
    }
};
