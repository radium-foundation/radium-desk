<?php

namespace App\Contracts\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;

interface EInvoiceGateway
{
    public function provider(): string;

    public function submit(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult;

    public function fetchExisting(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult;

    public function cancel(StatutoryInvoice $invoice, string $reason): void;
}
