<?php

namespace Tests\Unit\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\NewContactIntent;
use App\Services\IncomingEmail\IncomingEmailServiceCaseCategoryMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncomingEmailServiceCaseCategoryMapperTest extends TestCase
{
    private IncomingEmailServiceCaseCategoryMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new IncomingEmailServiceCaseCategoryMapper;
    }

    #[DataProvider('customerFacingProvider')]
    public function test_maps_customer_facing_classification(
        IncomingEmailClassification $classification,
        string $category,
        NewContactIntent $intent,
    ): void {
        $this->assertFalse($this->mapper->isInternalOperational($classification));
        $this->assertSame($category, $this->mapper->category($classification));
        $this->assertSame($intent, $this->mapper->intent($classification));
    }

    public static function customerFacingProvider(): array
    {
        return [
            [IncomingEmailClassification::Support, 'Service', NewContactIntent::GeneralSupport],
            [IncomingEmailClassification::ExistingCustomer, 'Service', NewContactIntent::GeneralSupport],
            [IncomingEmailClassification::Appointment, 'Appointment', NewContactIntent::GeneralSupport],
            [IncomingEmailClassification::Refund, 'Refund', NewContactIntent::Other],
            [IncomingEmailClassification::PossibleSalesLead, 'Sales Lead', NewContactIntent::BuyDevice],
            [IncomingEmailClassification::UnknownCustomer, 'General Support', NewContactIntent::GeneralSupport],
        ];
    }

    #[DataProvider('internalOperationalProvider')]
    public function test_internal_operational_is_detected(IncomingEmailClassification $classification): void
    {
        $this->assertTrue($this->mapper->isInternalOperational($classification));
        $this->expectException(InvalidArgumentException::class);
        $this->mapper->category($classification);
    }

    public static function internalOperationalProvider(): array
    {
        return [
            [IncomingEmailClassification::FinanceAction],
            [IncomingEmailClassification::HrAction],
            [IncomingEmailClassification::VendorAction],
        ];
    }

    public function test_spam_cannot_map_to_category(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mapper->category(IncomingEmailClassification::Spam);
    }
}
