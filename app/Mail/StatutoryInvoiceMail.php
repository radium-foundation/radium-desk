<?php

namespace App\Mail;

use App\Services\SupportContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StatutoryInvoiceMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $invoiceNumber,
        private readonly string $buyerName,
        private readonly string $pdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tax invoice '.$this->invoiceNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.statutory-invoice',
            with: app(SupportContactResolver::class)->mergeIntoVariables([
                'customer_name' => $this->buyerName,
                'invoice_number' => $this->invoiceNumber,
            ]),
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdfBinary, $this->invoiceNumber.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
