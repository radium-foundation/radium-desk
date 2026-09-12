<?php

namespace App\Contracts;

use App\Data\HistoricalSearchHit;

interface HistoricalSearchRepository
{
    /**
     * @return list<HistoricalSearchHit>
     */
    public function search(string $query, int $limit): array;
}
