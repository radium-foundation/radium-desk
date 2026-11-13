<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('oem', 16);
            $table->string('device_model', 64);
            $table->string('serial_number', 32);
            $table->char('handoff_token_hash', 64);
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamp('draft_submitted_at')->nullable();
            $table->timestamp('draft_reviewed_at')->nullable();
            $table->json('draft_payload')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'status']);
            $table->index(['user_id', 'expires_at']);
            $table->index('handoff_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_oem_handoff_sessions');
    }
};
