<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raise balanced-profile polling intervals that still match the previous
 * defaults. Custom / high_performance / manual values are left untouched.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private array $intervalBumps = [
        'performance.polling.notification_ms' => ['20000', '45000'],
        'performance.polling.operations_ms' => ['30000', '45000'],
        'performance.polling.operations_full_refresh_ms' => ['120000', '180000'],
        'performance.polling.customer360_timeline_ms' => ['30000', '45000'],
        'performance.polling.customer360_device_sync_ms' => ['10000', '15000'],
    ];

    public function up(): void
    {
        foreach ($this->intervalBumps as $key => [$from, $to]) {
            DB::table('system_settings')
                ->where('key', $key)
                ->where('value', $from)
                ->update([
                    'value' => $to,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->intervalBumps as $key => [$from, $to]) {
            DB::table('system_settings')
                ->where('key', $key)
                ->where('value', $to)
                ->update([
                    'value' => $from,
                    'updated_at' => now(),
                ]);
        }
    }
};
