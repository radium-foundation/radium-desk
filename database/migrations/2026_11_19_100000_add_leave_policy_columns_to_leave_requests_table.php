<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_MANUAL_EXCEPTION = 'leave_req_manual_exc_status_idx';

    private const INDEX_POLICY_EFFECTIVE = 'leave_req_policy_eff_idx';

    public function up(): void
    {
        if (! Schema::hasColumn('leave_requests', 'approval_source')) {
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
            });
        }

        if (! $this->indexExists(self::INDEX_MANUAL_EXCEPTION)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(['user_id', 'is_manual_exception', 'status'], self::INDEX_MANUAL_EXCEPTION);
            });
        }

        if (! $this->indexExists(self::INDEX_POLICY_EFFECTIVE)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(['policy_effective_start_date', 'policy_effective_end_date'], self::INDEX_POLICY_EFFECTIVE);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists(self::INDEX_MANUAL_EXCEPTION)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_MANUAL_EXCEPTION);
            });
        }

        if ($this->indexExists(self::INDEX_POLICY_EFFECTIVE)) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_POLICY_EFFECTIVE);
            });
        }

        if (Schema::hasColumn('leave_requests', 'approval_source')) {
            Schema::table('leave_requests', function (Blueprint $table): void {
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
    }

    private function indexExists(string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('leave_requests')");
            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, 'leave_requests', $indexName],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
