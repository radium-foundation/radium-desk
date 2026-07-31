<?php

use App\Models\TeamMemberWorkSchedule;
use App\Services\Operations\WorkCalendarService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated schedule versions: multiple rows per user, one active per date.
 * Existing single rows become open-ended versions (effective_to null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_member_work_schedules', function (Blueprint $table): void {
            $table->date('effective_from')->default('2026-07-01')->after('user_id');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->unsignedInteger('expected_working_minutes')->nullable()->after('weekly_off_days');
            $table->foreignId('created_by')->nullable()->after('expected_working_minutes')->constrained('users')->nullOnDelete();
        });

        $calendar = app(WorkCalendarService::class);

        TeamMemberWorkSchedule::query()->orderBy('id')->each(function (TeamMemberWorkSchedule $schedule) use ($calendar): void {
            $effectiveFrom = $schedule->created_at?->toDateString() ?? '2026-07-01';

            DB::table('team_member_work_schedules')
                ->where('id', $schedule->id)
                ->update([
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'expected_working_minutes' => $calendar->expectedWorkingMinutes($schedule),
                    'created_by' => null,
                ]);
        });

        // Drop temporary default so new rows must set effective_from explicitly (MySQL).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE team_member_work_schedules ALTER COLUMN effective_from DROP DEFAULT');
        }

        Schema::table('team_member_work_schedules', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->index(['user_id', 'effective_from']);
            $table->index(['user_id', 'effective_to']);
        });
    }

    public function down(): void
    {
        $keepIds = DB::table('team_member_work_schedules')
            ->selectRaw('MAX(id) as id')
            ->groupBy('user_id')
            ->pluck('id');

        DB::table('team_member_work_schedules')
            ->whereNotIn('id', $keepIds)
            ->delete();

        Schema::table('team_member_work_schedules', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'effective_from']);
            $table->dropIndex(['user_id', 'effective_to']);
        });

        Schema::table('team_member_work_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['effective_from', 'effective_to', 'expected_working_minutes']);
            $table->unique('user_id');
        });
    }
};
