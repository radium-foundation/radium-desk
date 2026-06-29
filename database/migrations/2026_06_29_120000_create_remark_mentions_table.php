<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remark_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remark_id')->constrained('remarks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['remark_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remark_mentions');
    }
};
