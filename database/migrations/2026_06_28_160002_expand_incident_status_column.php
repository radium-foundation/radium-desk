<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('status', 50)->default('open')->change();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed', 'awaiting_product_details'])
                ->default('open')
                ->change();
        });
    }
};
