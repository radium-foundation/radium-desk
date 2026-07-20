<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('hold_type');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'cleared_at']);
            $table->index(['hold_type', 'cleared_at']);
            $table->index(['source_type', 'source_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX business_holds_one_active_per_incident '
                .'ON business_holds (incident_id) WHERE cleared_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_holds');
    }
};
