<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_template_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('template_key');
            $table->string('template_name');
            $table->string('template_display_name');
            $table->string('template_purpose');
            $table->string('trigger_source');
            $table->string('status');
            $table->string('customer_phone', 50)->nullable();
            $table->string('interakt_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'dispatched_at']);
            $table->index(['incident_id', 'template_key']);
            $table->index('interakt_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_template_dispatches');
    }
};
