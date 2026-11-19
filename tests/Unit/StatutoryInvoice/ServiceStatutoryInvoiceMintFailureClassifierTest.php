<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintFailureClassifier;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoicePermanentFailureException;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceTemporaryFailureException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStatutoryInvoiceMintFailureClassifierTest extends TestCase
{
    private ServiceStatutoryInvoiceMintFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ServiceStatutoryInvoiceMintFailureClassifier;
    }

    #[Test]
    public function missing_service_commerce_is_temporary_upstream_failure(): void
    {
        $classified = $this->classifier->toRetryableException(
            new RadiumBoxInvalidResponseException(
                'RadiumBox service lookup response is missing service_commerce.',
            ),
        );

        $this->assertInstanceOf(ServiceStatutoryInvoiceTemporaryFailureException::class, $classified);
        $this->assertSame('missing_service_commerce_upstream', $classified->classification);
    }

    #[Test]
    public function other_radiumbox_invalid_responses_remain_permanent(): void
    {
        $classified = $this->classifier->toRetryableException(
            new RadiumBoxInvalidResponseException('RadiumBox service lookup response is missing data.'),
        );

        $this->assertInstanceOf(ServiceStatutoryInvoicePermanentFailureException::class, $classified);
    }
}