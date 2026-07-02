<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->date('preferred_date');
            $table->string('preferred_time_slot');
            $table->string('phone_number', 20);
            $table->text('additional_notes')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'preferred_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_appointments');
    }
};
