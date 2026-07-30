<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_workspace_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('call_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('customer_need')->nullable();
            $table->string('email')->nullable();
            $table->boolean('whatsapp_same_number')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('city')->nullable();
            $table->string('source')->nullable();
            $table->string('order_id_hint')->nullable();
            $table->text('agent_notes')->nullable();
            $table->string('disposition')->nullable();
            $table->string('next_action')->nullable();
            $table->string('current_step')->nullable();
            $table->json('completed_fields')->nullable();
            $table->json('skipped_fields')->nullable();
            $table->string('status')->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('incident_id');
            $table->index('call_id');
            $table->index('disposition');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_workspace_sessions');
    }
};
