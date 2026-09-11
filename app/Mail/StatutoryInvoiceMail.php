<?php

namespace App\Mail;

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
            htmlString: '<p>Dear '.e($this->buyerName).',</p>'
                .'<p>Please find your tax invoice <strong>'.e($this->invoiceNumber).'</strong> attached.</p>'
                .'<p>Thank you for your purchase.</p>',
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
