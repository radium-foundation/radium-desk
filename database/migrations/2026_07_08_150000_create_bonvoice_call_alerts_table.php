<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonvoice_call_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonvoice_call_event_id')->constrained('bonvoice_call_events')->cascadeOnDelete();
            $table->string('call_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('alert_type');
            $table->string('customer_phone', 50)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamp('notified_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonvoice_call_alerts');
    }
};
