<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interakt_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('customer_phone', 50);
            $table->string('direction');
            $table->string('message_type')->nullable();
            $table->text('text')->nullable();
            $table->string('media_url')->nullable();
            $table->string('template_name')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('customer_phone');
            $table->index(['customer_phone', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interakt_messages');
    }
};
