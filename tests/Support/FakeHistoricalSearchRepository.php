<?php

namespace Tests\Support;

use App\Contracts\HistoricalSearchRepository;
use App\Data\HistoricalSearchHit;

class FakeHistoricalSearchRepository implements HistoricalSearchRepository
{
    /** @var list<HistoricalSearchHit> */
    public static array $hits = [];

    public static ?\Throwable $exception = null;

    public static function reset(): void
    {
        self::$hits = [];
        self::$exception = null;
    }

    /**
     * @return list<HistoricalSearchHit>
     */
    public function search(string $query, int $limit): array
    {
        if (self::$exception !== null) {
            throw self::$exception;
        }

        return array_slice(self::$hits, 0, $limit);
    }
}
