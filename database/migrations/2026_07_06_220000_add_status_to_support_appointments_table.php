<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ONE_SCHEDULED_INDEX = 'support_appointments_one_scheduled_per_incident';

    private const UNIQUE_ACTIVE_SLOT_INDEX = 'support_appointments_unique_active_slot';

    private const SCHEDULED_INCIDENT_KEY_COLUMN = 'scheduled_incident_key';

    private const SCHEDULED_SLOT_KEY_COLUMN = 'scheduled_slot_key';

    public function up(): void
    {
        Schema::table('support_appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_appointments', 'status')) {
                $table->string('status')->default('scheduled')->after('additional_notes');
            }

            if (! Schema::hasColumn('support_appointments', 'normalized_phone')) {
                $table->string('normalized_phone', 20)->default('')->after('phone_number');
            }

            if (! $this->hasIndex(['incident_id', 'status'])) {
                $table->index(['incident_id', 'status']);
            }
        });

        DB::table('support_appointments')->orderBy('id')->lazy()->each(function (object $appointment): void {
            if (filled($appointment->normalized_phone ?? null)) {
                return;
            }

            DB::table('support_appointments')
                ->where('id', $appointment->id)
                ->update([
                    'normalized_phone' => preg_replace('/\D+/', '', (string) $appointment->phone_number) ?? '',
                ]);
        });

        $this->dedupeScheduledAppointments();

        $this->createScheduledUniqueIndexes();
    }

    public function down(): void
    {
        $this->dropScheduledUniqueIndexes();

        Schema::table('support_appointments', function (Blueprint $table): void {
            if ($this->hasIndex(['incident_id', 'status'])) {
                $table->dropIndex(['incident_id', 'status']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('support_appointments', 'status') ? 'status' : null,
                Schema::hasColumn('support_appointments', 'normalized_phone') ? 'normalized_phone' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function createScheduledUniqueIndexes(): void
    {
        if ($this->hasNamedIndex(self::ONE_SCHEDULED_INDEX)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::ONE_SCHEDULED_INDEX.' '
                .'ON support_appointments (incident_id) WHERE status = \'scheduled\''
            );

            DB::statement(
                'CREATE UNIQUE INDEX '.self::UNIQUE_ACTIVE_SLOT_INDEX.' '
                .'ON support_appointments (incident_id, preferred_date, preferred_time_slot, normalized_phone) '
                .'WHERE status = \'scheduled\''
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::ONE_SCHEDULED_INDEX.' '
                .'ON support_appointments (incident_id) WHERE status = \'scheduled\''
            );

            DB::statement(
                'CREATE UNIQUE INDEX '.self::UNIQUE_ACTIVE_SLOT_INDEX.' '
                .'ON support_appointments (incident_id, preferred_date, preferred_time_slot, normalized_phone) '
                .'WHERE status = \'scheduled\''
            );

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if ($this->isMariaDb()) {
            $this->createMariaDbScheduledUniqueIndexes();

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::ONE_SCHEDULED_INDEX.' '
            .'ON support_appointments ((IF(`status` = \'scheduled\', `incident_id`, NULL)))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX '.self::UNIQUE_ACTIVE_SLOT_INDEX.' '
            .'ON support_appointments ((IF(`status` = \'scheduled\', '
            .'CONCAT(`incident_id`, \'|\', `preferred_date`, \'|\', `preferred_time_slot`, \'|\', `normalized_phone`), NULL)))'
        );
    }

    private function createMariaDbScheduledUniqueIndexes(): void
    {
        if (! Schema::hasColumn('support_appointments', self::SCHEDULED_INCIDENT_KEY_COLUMN)) {
            DB::statement(
                'ALTER TABLE support_appointments ADD COLUMN '.self::SCHEDULED_INCIDENT_KEY_COLUMN
                .' BIGINT UNSIGNED AS (IF(`status` = \'scheduled\', `incident_id`, NULL)) VIRTUAL'
            );
        }

        if (! Schema::hasColumn('support_appointments', self::SCHEDULED_SLOT_KEY_COLUMN)) {
            DB::statement(
                'ALTER TABLE support_appointments ADD COLUMN '.self::SCHEDULED_SLOT_KEY_COLUMN
                .' VARCHAR(255) AS (IF(`status` = \'scheduled\', '
                .'CONCAT(`incident_id`, \'|\', `preferred_date`, \'|\', `preferred_time_slot`, \'|\', `normalized_phone`), NULL)) VIRTUAL'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::ONE_SCHEDULED_INDEX
            .' ON support_appointments ('.self::SCHEDULED_INCIDENT_KEY_COLUMN.')'
        );

        DB::statement(
            'CREATE UNIQUE INDEX '.self::UNIQUE_ACTIVE_SLOT_INDEX
            .' ON support_appointments ('.self::SCHEDULED_SLOT_KEY_COLUMN.')'
        );
    }

    private function dedupeScheduledAppointments(): void
    {
        $scheduledAppointments = DB::table('support_appointments')
            ->select([
                'id',
                'incident_id',
                'preferred_date',
                'preferred_time_slot',
                'normalized_phone',
            ])
            ->where('status', 'scheduled')
            ->orderByDesc('id')
            ->get();

        $keptIncidentIds = [];
        $keptSlotKeys = [];
        $supersededIds = [];

        foreach ($scheduledAppointments as $appointment) {
            $incidentId = (int) $appointment->incident_id;
            $slotKey = implode('|', [
                $incidentId,
                (string) $appointment->preferred_date,
                (string) $appointment->preferred_time_slot,
                (string) $appointment->normalized_phone,
            ]);

            if (isset($keptIncidentIds[$incidentId]) || isset($keptSlotKeys[$slotKey])) {
                $supersededIds[] = (int) $appointment->id;

                continue;
            }

            $keptIncidentIds[$incidentId] = true;
            $keptSlotKeys[$slotKey] = true;
        }

        if ($supersededIds === []) {
            return;
        }

        DB::table('support_appointments')
            ->whereIn('id', $supersededIds)
            ->update(['status' => 'superseded']);
    }

    private function dropScheduledUniqueIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.self::ONE_SCHEDULED_INDEX);
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_ACTIVE_SLOT_INDEX);

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::ONE_SCHEDULED_INDEX);
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_ACTIVE_SLOT_INDEX);

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if ($this->hasNamedIndex(self::ONE_SCHEDULED_INDEX)) {
            DB::statement('DROP INDEX '.self::ONE_SCHEDULED_INDEX.' ON support_appointments');
        }

        if ($this->hasNamedIndex(self::UNIQUE_ACTIVE_SLOT_INDEX)) {
            DB::statement('DROP INDEX '.self::UNIQUE_ACTIVE_SLOT_INDEX.' ON support_appointments');
        }

        if (Schema::hasColumn('support_appointments', self::SCHEDULED_SLOT_KEY_COLUMN)) {
            Schema::table('support_appointments', function (Blueprint $table): void {
                $table->dropColumn(self::SCHEDULED_SLOT_KEY_COLUMN);
            });
        }

        if (Schema::hasColumn('support_appointments', self::SCHEDULED_INCIDENT_KEY_COLUMN)) {
            Schema::table('support_appointments', function (Blueprint $table): void {
                $table->dropColumn(self::SCHEDULED_INCIDENT_KEY_COLUMN);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(array $columns): bool
    {
        foreach (Schema::getIndexes('support_appointments') as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedIndex(string $indexName): bool
    {
        foreach (Schema::getIndexes('support_appointments') as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }

    private function isMariaDb(): bool
    {
        $version = Schema::getConnection()->selectOne('select version() as version');

        return str_contains(strtolower((string) ($version->version ?? '')), 'mariadb');
    }
};
