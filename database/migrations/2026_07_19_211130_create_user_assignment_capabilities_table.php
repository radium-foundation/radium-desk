<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_assignment_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 64);
            $table->timestamps();

            $table->unique(['user_id', 'capability']);
            $table->index('capability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assignment_capabilities');
    }
};
