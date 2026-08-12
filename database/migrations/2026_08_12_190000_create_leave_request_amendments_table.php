<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('source', 32);
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->date('previous_start_date');
            $table->date('previous_end_date');
            $table->string('previous_duration', 20);
            $table->date('proposed_start_date')->nullable();
            $table->date('proposed_end_date')->nullable();
            $table->string('proposed_duration', 20)->nullable();
            $table->text('reason');
            $table->string('status', 32);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['leave_request_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_amendments');
    }
};
