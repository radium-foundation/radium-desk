<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interakt_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->longText('raw_body')->nullable();
            $table->json('request_headers')->nullable();
            $table->string('processing_status')->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('event_type');
            $table->index('processing_status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interakt_webhook_logs');
    }
};
