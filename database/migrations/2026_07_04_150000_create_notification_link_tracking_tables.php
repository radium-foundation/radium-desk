<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['incident_id', 'source']);
        });

        Schema::create('notification_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_link_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->timestamp('clicked_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'source', 'clicked_at']);
            $table->index(['source', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_link_clicks');
        Schema::dropIfExists('notification_link_tokens');
    }
};
