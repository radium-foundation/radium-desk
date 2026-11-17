<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareDashboardRowChunkResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class HardwareDashboardRowChunkResolverDeferTest extends TestCase
{
    public function test_defer_expensive_counts_only_on_first_progressive_chunk(): void
    {
        $resolver = new HardwareDashboardRowChunkResolver;

        $first = Request::create('/', 'GET', [
            'hw_progressive' => '1',
            'hw_chunk_limit' => 4,
            'hw_chunk_offset' => 0,
        ]);

        $followUp = Request::create('/', 'GET', [
            'hw_progressive' => '1',
            'hw_chunk_limit' => 6,
            'hw_chunk_offset' => 4,
            'hw_skip_counts' => 1,
        ]);

        $this->assertTrue($resolver->deferExpensiveCounts($first));
        $this->assertFalse($resolver->deferExpensiveCounts($followUp));
    }
}
