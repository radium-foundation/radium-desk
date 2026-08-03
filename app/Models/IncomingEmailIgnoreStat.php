<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class IncomingEmailIgnoreStat extends Model
{
    protected $fillable = [
        'stat_date',
        'reason',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'count' => 'integer',
        ];
    }

    public static function incrementReason(string $reason, ?\DateTimeInterface $date = null): void
    {
        $statDate = ($date ?? now())->format('Y-m-d');
        $normalizedReason = trim($reason) !== '' ? trim($reason) : 'unknown';

        $affected = self::query()
            ->where('stat_date', $statDate)
            ->where('reason', $normalizedReason)
            ->update([
                'count' => DB::raw('count + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            try {
                self::query()->create([
                    'stat_date' => $statDate,
                    'reason' => $normalizedReason,
                    'count' => 1,
                ]);
            } catch (\Throwable) {
                self::query()
                    ->where('stat_date', $statDate)
                    ->where('reason', $normalizedReason)
                    ->update([
                        'count' => DB::raw('count + 1'),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
}
