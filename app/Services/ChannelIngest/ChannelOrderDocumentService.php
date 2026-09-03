<?php

namespace App\Services\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Models\CommerceOrder;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceScope;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Illuminate\Validation\ValidationException;

final class ChannelOrderDocumentService
{
    public function __construct(
        private readonly StatutoryDocumentService $documents,
        private readonly StatutoryMintEligibility $eligibility,
    ) {}

    /**
     * @return array{binary: string, filename: string, content_type: string}|null
     */
    public function find(
        StatutoryInvoiceChannel $channel,
        string $sourceType,
        string $sourceId,
        ?string $customerClaim = null,
    ): ?array {
        $order = CommerceOrder::query()
            ->where('channel', $channel->value)
            ->where('source_type', trim($sourceType))
            ->where('source_id', trim($sourceId))
            ->first();

        if ($order === null) {
            return null;
        }

        if (! StatutoryInvoiceScope::contains($this->eligibility->commercialDate($order))) {
            throw ValidationException::withMessages([
                'scope' => 'This order is outside the 2026-09-01 invoice scope.',
            ]);
        }

        if ($customerClaim !== null && $customerClaim !== '' && ! $this->customerOwnsOrder($order, $customerClaim)) {
            return null;
        }

        if ($order->statutory_invoice_id === null || $order->statutoryInvoice === null) {
            return null;
        }

        $invoice = $order->statutoryInvoice;
        try {
            $document = $this->documents->generate($invoice);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'document' => 'The statutory PDF is not available.',
            ]);
        }

        if ($document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            throw ValidationException::withMessages([
                'document' => 'The statutory PDF is not available.',
            ]);
        }

        $safeNumber = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice->invoice_number) ?: 'invoice';

        return [
            'binary' => $this->documents->binary($document),
            'filename' => $safeNumber.'.pdf',
            'content_type' => 'application/pdf',
        ];
    }

    private function customerOwnsOrder(CommerceOrder $order, string $claim): bool
    {
        $claim = strtolower(trim($claim));
        if ($claim === '') {
            return false;
        }

        $email = is_string($order->customer_email) ? strtolower(trim($order->customer_email)) : '';
        $phone = is_string($order->customer_phone) ? preg_replace('/\D+/', '', $order->customer_phone) : '';
        $claimDigits = preg_replace('/\D+/', '', $claim) ?? '';

        return ($email !== '' && hash_equals($email, $claim))
            || ($phone !== '' && $claimDigits !== '' && hash_equals($phone, $claimDigits));
    }
}
