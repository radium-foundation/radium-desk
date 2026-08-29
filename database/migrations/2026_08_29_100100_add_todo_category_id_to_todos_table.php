<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->foreignId('todo_category_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('todo_categories')
                ->restrictOnDelete();

            $table->index('todo_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('todo_category_id');
        });
    }
};
