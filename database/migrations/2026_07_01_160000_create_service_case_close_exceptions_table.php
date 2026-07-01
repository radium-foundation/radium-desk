<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_case_close_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('exception_id', 32)->unique();
            $table->boolean('serial_number_unavailable')->default(false);
            $table->boolean('reference_number_unavailable')->default(false);
            $table->string('reason');
            $table->text('reason_custom')->nullable();
            $table->boolean('notify_whatsapp')->default(false);
            $table->boolean('notify_email')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('exception_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_case_close_exceptions');
    }
};
