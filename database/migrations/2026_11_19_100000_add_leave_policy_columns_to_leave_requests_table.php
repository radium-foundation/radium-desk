<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('approval_source', 32)->default('manual')->after('status');
            $table->string('policy_phase', 32)->nullable()->after('approval_source');
            $table->boolean('requires_manual_review')->default(true)->after('policy_phase');
            $table->boolean('is_manual_exception')->default(false)->after('requires_manual_review');
            $table->boolean('is_half_day_exception')->default(false)->after('is_manual_exception');
            $table->boolean('authorized_override')->default(false)->after('is_half_day_exception');
            $table->foreignId('authorized_override_by')->nullable()->after('authorized_override')
                ->constrained('users')->nullOnDelete();
            $table->text('authorized_override_reason')->nullable()->after('authorized_override_by');
            $table->date('policy_effective_start_date')->nullable()->after('end_date');
            $table->date('policy_effective_end_date')->nullable()->after('policy_effective_start_date');
            $table->unsignedSmallInteger('notice_calendar_days')->nullable()->after('policy_effective_end_date');
            $table->json('policy_metadata')->nullable()->after('notice_calendar_days');

            $table->index(['user_id', 'is_manual_exception', 'status']);
            $table->index(['policy_effective_start_date', 'policy_effective_end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_manual_exception', 'status']);
            $table->dropIndex(['policy_effective_start_date', 'policy_effective_end_date']);
            $table->dropConstrainedForeignId('authorized_override_by');
            $table->dropColumn([
                'approval_source',
                'policy_phase',
                'requires_manual_review',
                'is_manual_exception',
                'is_half_day_exception',
                'authorized_override',
                'authorized_override_reason',
                'policy_effective_start_date',
                'policy_effective_end_date',
                'notice_calendar_days',
                'policy_metadata',
            ]);
        });
    }
};
