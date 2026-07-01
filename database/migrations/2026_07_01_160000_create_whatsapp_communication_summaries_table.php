<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_communication_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('customer_phone', 50)->unique();
            $table->string('conversation_id')->nullable();
            $table->string('interakt_customer_id')->nullable();
            $table->string('conversation_status');
            $table->unsignedInteger('messages_exchanged_count')->default(0);
            $table->unsignedInteger('unread_count')->nullable();
            $table->string('last_sender')->nullable();
            $table->string('last_template_name')->nullable();
            $table->string('last_message_id')->nullable();
            $table->string('last_delivery_status')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_communication_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('interakt_customer_id');
            $table->index('last_message_id');
            $table->index('last_template_name');
            $table->index(['customer_phone', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_communication_summaries');
    }
};
