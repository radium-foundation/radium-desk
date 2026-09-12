<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\CanonicalHistoricalSearchRepository;
use App\Services\HistoricalSearch\HistoricalSearchQueryNormalizer;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CanonicalHistoricalSearchRepositoryTest extends TestCase
{
    public function test_repository_uses_read_only_select_queries(): void
    {
        $connection = Mockery::mock(Connection::class);
        $builder = Mockery::mock(Builder::class);

        DB::shouldReceive('connection')
            ->with('radium_hist')
            ->andReturn($connection);
        DB::shouldReceive('raw')
            ->andReturnUsing(static fn (mixed $value): mixed => $value);

        $connection->shouldReceive('statement')->andReturn(true);
        $connection->shouldReceive('table')->andReturn($builder);

        $builder->shouldReceive('select')->andReturnSelf();
        $builder->shouldReceive('where')->andReturnSelf();
        $builder->shouldReceive('whereRaw')->andReturnSelf();
        $builder->shouldReceive('orWhere')->andReturnSelf();
        $builder->shouldReceive('leftJoin')->andReturnSelf();
        $builder->shouldReceive('join')->andReturnSelf();
        $builder->shouldReceive('limit')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect());

        $connection->shouldNotReceive('insert');
        $connection->shouldNotReceive('update');
        $connection->shouldNotReceive('delete');
        $builder->shouldNotReceive('insert');
        $builder->shouldNotReceive('update');
        $builder->shouldNotReceive('delete');

        config([
            'historical_search.connection' => 'radium_hist',
            'historical_search.timeout_ms' => 100,
        ]);

        $repository = new CanonicalHistoricalSearchRepository(new HistoricalSearchQueryNormalizer());
        $repository->search('SN-READ-ONLY-1234', 5);

        $this->addToAssertionCount(1);
    }
}
