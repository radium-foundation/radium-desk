<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalPaymentPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistoricalPaymentPresenterTest extends TestCase
{
    private HistoricalPaymentPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new HistoricalPaymentPresenter;
    }

    #[DataProvider('paidStatusesProvider')]
    public function test_verified_paid_statuses_render_paid(string $status): void
    {
        $result = $this->presenter->present($status);

        $this->assertSame('paid', $result['display']);
    }

    public static function paidStatusesProvider(): array
    {
        return [
            ['Paid'],
            ['SUCCESS'],
            ['payment received'],
            ['completed'],
        ];
    }

    public function test_unknown_payment_status_does_not_render_paid(): void
    {
        $result = $this->presenter->present(null);

        $this->assertSame('unknown', $result['display']);
    }

    public function test_unpaid_status_renders_unpaid(): void
    {
        $result = $this->presenter->present('pending');

        $this->assertSame('unpaid', $result['display']);
    }

    public function test_partial_status_renders_partial(): void
    {
        $result = $this->presenter->present('partially_paid');

        $this->assertSame('partial', $result['display']);
    }
}
