<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiumbox_statutory_receipt_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('statutory_invoice_id')->unique('rbox_stat_rcpt_pub_inv_uq');
            $table->string('http_idempotency_key', 191)->unique('rbox_stat_rcpt_pub_idem_uq');
            $table->string('status', 32);
            $table->string('box_receipt_id', 26)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->boolean('duplicate_delivery')->default(false);
            $table->string('eligibility_code', 64)->nullable();
            $table->string('last_error', 512)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('last_outbox_event_id')->nullable();
            $table->timestamps();

            $table->foreign('statutory_invoice_id', 'rbox_stat_rcpt_pub_inv_fk')
                ->references('id')
                ->on('statutory_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiumbox_statutory_receipt_publications');
    }
};
