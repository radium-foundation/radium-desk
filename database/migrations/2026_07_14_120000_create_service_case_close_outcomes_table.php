<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_case_close_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('reason_for_closing');
            $table->string('resolution_type')->nullable();
            $table->json('metadata')->nullable();
            $table->text('closing_summary');
            $table->string('notification_preference');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->index('reason_for_closing');
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_case_close_outcomes');
    }
};
