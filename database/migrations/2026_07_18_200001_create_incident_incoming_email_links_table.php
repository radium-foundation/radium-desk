<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_incoming_email_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('incoming_email_message_id')->constrained('incoming_email_messages')->cascadeOnDelete();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique('incoming_email_message_id', 'iiel_message_uq');
            $table->index(['incident_id', 'linked_at'], 'iiel_incident_linked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_incoming_email_links');
    }
};
