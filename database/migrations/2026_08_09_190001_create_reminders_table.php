<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->morphs('remindable');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('remind_at');
            $table->string('status', 32)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('notification_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
