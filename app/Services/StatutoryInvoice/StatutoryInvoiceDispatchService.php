<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Mail\StatutoryInvoiceMail;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDispatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StatutoryInvoiceDispatchService
{
    public function __construct(
        private readonly StatutoryDocumentService $documents,
    ) {}

    public function sendEmail(StatutoryInvoice $invoice, string $recipientEmail, User $actor): StatutoryInvoiceDispatch
    {
        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '' || ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        $invoice->loadMissing('document');
        $document = $invoice->document;
        if ($document === null || $document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            throw ValidationException::withMessages([
                'invoice' => 'The statutory PDF is not ready yet.',
            ]);
        }

        $duplicate = StatutoryInvoiceDispatch::query()
            ->where('invoice_id', $invoice->id)
            ->where('channel', 'email')
            ->where('destination', $recipientEmail)
            ->where('status', 'sent')
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => 'This invoice was already emailed to that address.',
            ]);
        }

        $binary = $this->documents->binary($document);
        $mail = new StatutoryInvoiceMail(
            invoiceNumber: (string) $invoice->invoice_number,
            buyerName: (string) ($invoice->buyer_name ?: 'Customer'),
            pdfBinary: $binary,
        );

        try {
            Mail::to($recipientEmail)->send($mail);
            $status = 'sent';
            $error = null;
        } catch (Throwable $exception) {
            Log::error('statutory_invoice.email_failed', [
                'invoice_id' => $invoice->id,
                'recipient_email' => $recipientEmail,
                'error' => $exception->getMessage(),
            ]);
            $status = 'failed';
            $error = $exception->getMessage();
        }

        return DB::transaction(function () use ($invoice, $recipientEmail, $actor, $status, $error): StatutoryInvoiceDispatch {
            return StatutoryInvoiceDispatch::query()->create([
                'invoice_id' => $invoice->id,
                'channel' => 'email',
                'destination' => $recipientEmail,
                'sent_by' => $actor->id,
                'sent_at' => now(),
                'status' => $status,
                'last_error' => $error,
            ]);
        });
    }

    public function whatsAppShareUrl(StatutoryInvoice $invoice, ?string $phone = null): string
    {
        $phone = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $message = rawurlencode(
            'Your tax invoice '.$invoice->invoice_number.' from Radium is ready. '
            .'Please contact our store team if you need a copy.'
        );

        if ($phone !== '') {
            return 'https://wa.me/'.$phone.'?text='.$message;
        }

        return 'https://wa.me/?text='.$message;
    }
}
