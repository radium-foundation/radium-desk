<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareDashboardPageSizer;
use Illuminate\Http\Request;
use Tests\TestCase;

class HardwareDashboardPageSizerTest extends TestCase
{
    public function test_progressive_first_page_uses_initial_size(): void
    {
        $sizer = new HardwareDashboardPageSizer(10, 40, 10);
        $request = Request::create('/', 'GET', ['hw_progressive' => '1']);

        $slice = $sizer->sliceFor($request, 1);

        $this->assertSame(0, $slice['offset']);
        $this->assertSame(10, $slice['limit']);
        $this->assertSame(10, $slice['per_page']);
    }

    public function test_progressive_second_page_offsets_past_initial_batch(): void
    {
        $sizer = new HardwareDashboardPageSizer(10, 40, 10);
        $request = Request::create('/', 'GET', ['hw_progressive' => '1']);

        $slice = $sizer->sliceFor($request, 2);

        $this->assertSame(10, $slice['offset']);
        $this->assertSame(40, $slice['limit']);
    }

    public function test_progressive_last_page_for_mixed_sizes(): void
    {
        $sizer = new HardwareDashboardPageSizer(10, 40, 10);
        $request = Request::create('/', 'GET', ['hw_progressive' => '1']);

        $this->assertSame(2, $sizer->lastPage(45, $request));
        $this->assertSame(1, $sizer->lastPage(8, $request));
    }

    public function test_legacy_mode_keeps_standard_page_size(): void
    {
        $sizer = new HardwareDashboardPageSizer(10, 40, 10);
        $request = Request::create('/', 'GET');

        $slice = $sizer->sliceFor($request, 2);

        $this->assertSame(40, $slice['offset']);
        $this->assertSame(40, $slice['limit']);
        $this->assertSame(2, $sizer->lastPage(45, $request));
    }
}
