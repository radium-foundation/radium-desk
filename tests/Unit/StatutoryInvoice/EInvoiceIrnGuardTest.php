<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Services\StatutoryInvoice\EInvoiceIrnGuard;
use Tests\TestCase;

class EInvoiceIrnGuardTest extends TestCase
{
    public function test_processing_and_ambiguous_without_irn_must_recover(): void
    {
        $processing = new EInvoiceRecord(['status' => EInvoiceRecordStatus::Processing->value, 'irn' => null]);
        $ambiguous = new EInvoiceRecord(['status' => EInvoiceRecordStatus::Ambiguous->value, 'irn' => null]);
        $queued = new EInvoiceRecord(['status' => EInvoiceRecordStatus::Queued->value, 'irn' => null]);
        $temporary = new EInvoiceRecord(['status' => EInvoiceRecordStatus::TemporaryFailure->value, 'irn' => null]);
        $issued = new EInvoiceRecord([
            'status' => EInvoiceRecordStatus::Processing->value,
            'irn' => 'already-issued',
        ]);

        $this->assertTrue(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($processing));
        $this->assertTrue(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($ambiguous));
        $this->assertFalse(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($queued));
        $this->assertFalse(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($temporary));
        $this->assertFalse(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($issued));
        $this->assertFalse(EInvoiceIrnGuard::mustRecoverInsteadOfGenerate(null));
    }
}
