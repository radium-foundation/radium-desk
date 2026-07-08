<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_bonvoice_call_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('bonvoice_call_event_id')->constrained('bonvoice_call_events')->cascadeOnDelete();
            $table->string('call_id', 100);
            $table->string('link_type', 20);
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(['incident_id', 'bonvoice_call_event_id']);
            $table->unique(['call_id', 'link_type']);
            $table->index(['incident_id', 'link_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_bonvoice_call_links');
    }
};
