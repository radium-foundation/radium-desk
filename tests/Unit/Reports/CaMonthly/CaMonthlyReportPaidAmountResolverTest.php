<?php

namespace Tests\Unit\Reports\CaMonthly;

use App\Reports\CaMonthly\CaMonthlyReportPaidAmountResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CaMonthlyReportPaidAmountResolverTest extends TestCase
{
    private CaMonthlyReportPaidAmountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CaMonthlyReportPaidAmountResolver;
    }

    #[DataProvider('partialPaidToleranceProvider')]
    public function test_partial_paid_tolerance(float $invoice, float $paid, bool $expectedPartial): void
    {
        $this->assertSame($expectedPartial, $this->resolver->isPartiallyPaid($paid, $invoice));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: bool}>
     */
    public static function partialPaidToleranceProvider(): array
    {
        return [
            'exact payment' => [599.01, 599.01, false],
            'one paisa difference' => [599.01, 599.00, false],
            'fifty paisa difference' => [499.01, 498.51, false],
            'exactly one rupee difference' => [1000.00, 999.00, false],
            'one rupee one paisa difference' => [1000.00, 998.99, true],
            'large partial payment' => [1000.00, 900.00, true],
            'zero payment' => [1000.00, 0.00, false],
        ];
    }

    public function test_payment_difference_uses_absolute_value(): void
    {
        $this->assertSame(0.01, $this->resolver->paymentDifference(599.00, 599.01));
        $this->assertSame(1.00, $this->resolver->paymentDifference(999.00, 1000.00));
    }
}
