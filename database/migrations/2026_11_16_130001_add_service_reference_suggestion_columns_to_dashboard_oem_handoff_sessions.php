<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->string('suggested_service_reference', 32)->nullable()->after('oem_expiry_persist_detail');
            $table->date('suggested_service_reference_expiry_date')->nullable()->after('suggested_service_reference');
            $table->unsignedSmallInteger('suggested_service_reference_sequence')->nullable()->after('suggested_service_reference_expiry_date');
            $table->timestamp('suggested_service_reference_generated_at')->nullable()->after('suggested_service_reference_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'suggested_service_reference',
                'suggested_service_reference_expiry_date',
                'suggested_service_reference_sequence',
                'suggested_service_reference_generated_at',
            ]);
        });
    }
};