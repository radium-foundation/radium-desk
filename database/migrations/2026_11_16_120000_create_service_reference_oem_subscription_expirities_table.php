<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reference_oem_subscription_expiries', function (Blueprint $table) {
            $table->id();
            $table->string('service_reference', 100);
            $table->string('oem', 16);
            $table->date('subscription_expiry_date');
            $table->unsignedBigInteger('source_oem_handoff_session_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('service_reference', 'service_reference_oem_sub_expiry_unique');
            $table->index('subscription_expiry_date', 'sr_oem_sub_expiry_date_idx');
            $table->foreign('source_oem_handoff_session_id', 'sr_oem_exp_handoff_sess_fk')
                ->references('id')
                ->on('dashboard_oem_handoff_sessions')
                ->nullOnDelete();
            $table->foreign('updated_by_user_id', 'sr_oem_exp_updated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reference_oem_subscription_expiries');
    }
};
