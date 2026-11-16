<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('draft_reviewed_at');
            $table->timestamp('rejected_at')->nullable()->after('confirmed_at');
            $table->foreignId('confirmed_by_user_id')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->json('confirmed_payload')->nullable()->after('confirmed_by_user_id');
            $table->string('rejection_reason', 255)->nullable()->after('confirmed_payload');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_oem_handoff_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by_user_id');
            $table->dropColumn(['confirmed_at', 'rejected_at', 'confirmed_payload', 'rejection_reason']);
        });
    }
};