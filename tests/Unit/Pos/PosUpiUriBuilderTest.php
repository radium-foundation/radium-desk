<?php

namespace Tests\Unit\Pos;

use App\Support\Pos\PosUpiUriBuilder;
use InvalidArgumentException;
use Tests\TestCase;

class PosUpiUriBuilderTest extends TestCase
{
    public function test_builds_deterministic_encoded_uri(): void
    {
        $builder = new PosUpiUriBuilder;

        $first = $builder->build('desk-test@upi', 'Radium Desk Payee', '706.82', 'RDABC123');
        $second = $builder->build('desk-test@upi', 'Radium Desk Payee', '706.82', 'RDABC123');

        $this->assertSame($first, $second);
        $this->assertSame(
            'upi://pay?pa=desk-test%40upi&pn=Radium%20Desk%20Payee&am=706.82&tr=RDABC123&cu=INR',
            $first,
        );
    }

    public function test_formats_amount_to_two_decimals(): void
    {
        $this->assertSame('10.00', PosUpiUriBuilder::formatAmount(10));
        $this->assertSame('10.50', PosUpiUriBuilder::formatAmount(10.5));
        $this->assertSame('10.56', PosUpiUriBuilder::formatAmount('10.555'));
    }

    public function test_rejects_unformatted_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PosUpiUriBuilder)->build('desk-test@upi', 'Payee', '10', 'RD1');
    }
}
