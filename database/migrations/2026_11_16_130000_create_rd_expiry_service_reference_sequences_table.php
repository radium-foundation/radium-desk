<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rd_expiry_service_reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('expiry_date')->unique('rd_exp_sr_seq_expiry_unique');
            $table->unsignedInteger('last_allocated_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rd_expiry_service_reference_sequences');
    }
};