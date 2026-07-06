<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_appointments', function (Blueprint $table) {
            $table->string('status')->default('scheduled')->after('additional_notes');
            $table->string('normalized_phone', 20)->default('')->after('phone_number');
            $table->index(['incident_id', 'status']);
        });

        DB::table('support_appointments')->orderBy('id')->lazy()->each(function (object $appointment): void {
            DB::table('support_appointments')
                ->where('id', $appointment->id)
                ->update([
                    'normalized_phone' => preg_replace('/\D+/', '', (string) $appointment->phone_number) ?? '',
                ]);
        });

        $this->createScheduledUniqueIndexes();
    }

    public function down(): void
    {
        $this->dropScheduledUniqueIndexes();

        Schema::table('support_appointments', function (Blueprint $table) {
            $table->dropIndex(['incident_id', 'status']);
            $table->dropColumn(['status', 'normalized_phone']);
        });
    }

    private function createScheduledUniqueIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_one_scheduled_per_incident '
                .'ON support_appointments (incident_id) WHERE status = \'scheduled\''
            );

            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_unique_active_slot '
                .'ON support_appointments (incident_id, preferred_date, preferred_time_slot, normalized_phone) '
                .'WHERE status = \'scheduled\''
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_one_scheduled_per_incident '
                .'ON support_appointments (incident_id) WHERE status = \'scheduled\''
            );

            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_unique_active_slot '
                .'ON support_appointments (incident_id, preferred_date, preferred_time_slot, normalized_phone) '
                .'WHERE status = \'scheduled\''
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_one_scheduled_per_incident '
                .'ON support_appointments ((IF(`status` = \'scheduled\', `incident_id`, NULL)))'
            );

            DB::statement(
                'CREATE UNIQUE INDEX support_appointments_unique_active_slot '
                .'ON support_appointments ((IF(`status` = \'scheduled\', '
                .'CONCAT(`incident_id`, \'|\', `preferred_date`, \'|\', `preferred_time_slot`, \'|\', `normalized_phone`), NULL)))'
            );
        }
    }

    private function dropScheduledUniqueIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS support_appointments_one_scheduled_per_incident');
            DB::statement('DROP INDEX IF EXISTS support_appointments_unique_active_slot');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS support_appointments_one_scheduled_per_incident');
            DB::statement('DROP INDEX IF EXISTS support_appointments_unique_active_slot');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX support_appointments_one_scheduled_per_incident ON support_appointments');
            DB::statement('DROP INDEX support_appointments_unique_active_slot ON support_appointments');
        }
    }
};
