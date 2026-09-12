<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalTenurePresenter;
use Tests\TestCase;

class HistoricalTenurePresenterTest extends TestCase
{
    private HistoricalTenurePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new HistoricalTenurePresenter;
    }

    public function test_non_rd_service_lineage_is_unknown(): void
    {
        $result = $this->presenter->present('commerce_active', 'expired', '2020-01-01');

        $this->assertSame('unknown', $result['display']);
        $this->assertNull($result['end_date']);
    }

    public function test_rd_service_expired_status_renders_ended_without_invented_end_date(): void
    {
        $result = $this->presenter->present('rd_service', 'expired', '2020-01-01');

        $this->assertSame('ended', $result['display']);
        $this->assertNull($result['end_date']);
    }

    public function test_rd_service_active_status_renders_active(): void
    {
        $result = $this->presenter->present('rd_service', 'active', '2024-06-01');

        $this->assertSame('active', $result['display']);
        $this->assertNull($result['end_date']);
    }

    public function test_missing_status_is_unknown(): void
    {
        $result = $this->presenter->present('rd_service', null, '2024-06-01');

        $this->assertSame('unknown', $result['display']);
    }
}
