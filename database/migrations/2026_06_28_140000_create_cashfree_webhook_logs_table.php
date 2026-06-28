<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashfree_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_version')->nullable();
            $table->json('request_headers');
            $table->json('request_payload');
            $table->longText('raw_body')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashfree_webhook_logs');
    }
};
