<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_invoice_remediation_records', function (Blueprint $table): void {
            $table->id();
            $table->string('remediation_key', 128)->unique();
            $table->unsignedBigInteger('statutory_invoice_id');
            $table->string('invoice_number', 64);
            $table->string('prompt_id', 64);
            $table->string('actor', 128);
            $table->string('evidence_reference', 512);
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->timestamp('applied_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('statutory_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_invoice_remediation_records');
    }
};
