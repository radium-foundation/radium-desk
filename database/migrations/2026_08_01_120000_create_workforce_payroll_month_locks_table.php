<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_month_locks', function (Blueprint $table): void {
            $table->id();
            $table->date('month')->unique();
            $table->foreignId('locked_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('locked_at');
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_month_locks');
    }
};
