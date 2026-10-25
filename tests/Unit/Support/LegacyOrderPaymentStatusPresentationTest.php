<?php

namespace Tests\Unit\Support;

use App\Support\LegacyOrderPaymentStatusPresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyOrderPaymentStatusPresentationTest extends TestCase
{
    private LegacyOrderPaymentStatusPresentation $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new LegacyOrderPaymentStatusPresentation;
    }

    public function test_verified_paid_status_renders_paid_tone(): void
    {
        $result = $this->presenter->present('Paid', 'Shipped');

        $this->assertSame('PAID', $result['label']);
        $this->assertSame('paid', $result['tone']);
        $this->assertSame('check', $result['icon']);
    }

    public function test_cashfree_success_maps_to_paid(): void
    {
        $result = $this->presenter->present('SUCCESS', 'Shipped');

        $this->assertSame('PAID', $result['label']);
        $this->assertSame('paid', $result['tone']);
    }

    #[DataProvider('pendingStatusProvider')]
    public function test_pending_status_is_not_paid(string $raw): void
    {
        $result = $this->presenter->present($raw, 'Shipped');

        $this->assertSame('pending', $result['tone']);
        $this->assertNotSame('paid', $result['tone']);
        $this->assertNotSame('PAID', $result['label']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pendingStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
            'initiated' => ['initiated'],
            'partial' => ['partially paid'],
        ];
    }

    #[DataProvider('failedStatusProvider')]
    public function test_failed_status_renders_failed(string $raw): void
    {
        $result = $this->presenter->present($raw, 'Shipped');

        $this->assertSame('FAILED', $result['label']);
        $this->assertSame('failed', $result['tone']);
        $this->assertSame('x', $result['icon']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failedStatusProvider(): array
    {
        return [
            'failed' => ['failed'],
            'declined' => ['declined'],
            'rejected' => ['rejected'],
        ];
    }

    public function test_missing_status_is_unknown_not_paid(): void
    {
        $result = $this->presenter->present(null, 'Shipped');

        $this->assertSame('STATUS UNKNOWN', $result['label']);
        $this->assertSame('unknown', $result['tone']);
    }

    public function test_unrecognized_status_is_unknown(): void
    {
        $result = $this->presenter->present('mystery-gateway-state', 'Shipped');

        $this->assertSame('STATUS UNKNOWN', $result['label']);
        $this->assertSame('unknown', $result['tone']);
    }

    public function test_unpaid_is_not_paid(): void
    {
        $result = $this->presenter->present('unpaid', 'Shipped');

        $this->assertSame('pending', $result['tone']);
        $this->assertNotSame('PAID', $result['label']);
    }

    public function test_shipped_with_pending_payment_stays_pending(): void
    {
        $result = $this->presenter->present('pending', 'Shipped');

        $this->assertSame('PENDING', $result['label']);
        $this->assertSame('pending', $result['tone']);
    }

    public function test_contradictory_paid_payment_and_pending_order_is_unknown(): void
    {
        $result = $this->presenter->present('Paid', 'Payment Pending');

        $this->assertSame('STATUS UNKNOWN', $result['label']);
        $this->assertSame('unknown', $result['tone']);
    }

    public function test_invoice_existence_does_not_affect_presenter(): void
    {
        $withShipment = $this->presenter->present('pending', 'Shipped');
        $withoutShipment = $this->presenter->present('pending', null);

        $this->assertSame($withShipment, $withoutShipment);
    }

    public function test_cancelled_status(): void
    {
        $result = $this->presenter->present('cancelled', 'Shipped');

        $this->assertSame('CANCELLED', $result['label']);
        $this->assertSame('cancelled', $result['tone']);
    }
}
