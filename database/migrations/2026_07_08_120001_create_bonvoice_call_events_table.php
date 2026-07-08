<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonvoice_call_events', function (Blueprint $table) {
            $table->id();
            $table->string('call_id');
            $table->string('leg');
            $table->string('customer_phone', 50)->nullable();
            $table->string('source_number', 50)->nullable();
            $table->string('destination_number', 50)->nullable();
            $table->string('display_number', 50)->nullable();
            $table->string('direction')->nullable();
            $table->string('status')->nullable();
            $table->string('agent_status')->nullable();
            $table->string('call_type')->nullable();
            $table->string('account_id')->nullable();
            $table->string('data_source')->nullable();
            $table->string('event_id')->nullable();
            $table->string('callback_parent_id')->nullable();
            $table->json('callback_params')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('webhook_log_id')->nullable()->constrained('bonvoice_webhook_logs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['call_id', 'leg']);
            $table->index('customer_phone');
            $table->index(['customer_phone', 'started_at']);
            $table->index('call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonvoice_call_events');
    }
};
