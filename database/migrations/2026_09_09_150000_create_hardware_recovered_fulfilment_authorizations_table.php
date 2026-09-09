<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_recovered_fulfilment_authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 80);
            $table->unsignedBigInteger('commerce_order_id');
            $table->string('commerce_order_no', 40);
            $table->string('purpose', 120);
            $table->string('status', 32);
            $table->timestamp('authorized_at');
            $table->string('authorized_by', 80);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 80)->nullable();
            $table->timestamps();

            $table->foreign('commerce_order_id', 'hw_recov_ff_co_fk')
                ->references('id')
                ->on('commerce_orders')
                ->restrictOnDelete();
            $table->unique(['channel', 'source_type', 'source_id'], 'hw_recov_ff_src_unique');
            $table->unique('commerce_order_id', 'hw_recov_ff_co_unique');
            $table->index('source_id', 'hw_recov_ff_source_idx');
            $table->index('status', 'hw_recov_ff_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_recovered_fulfilment_authorizations');
    }
};
