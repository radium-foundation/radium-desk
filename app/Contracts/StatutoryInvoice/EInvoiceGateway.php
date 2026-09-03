<?php

namespace App\Contracts\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;

interface EInvoiceGateway
{
    public function provider(): string;

    public function submit(StatutoryInvoice $invoice): EInvoiceSubmitResult;

    public function cancel(StatutoryInvoice $invoice, string $reason): void;
}
