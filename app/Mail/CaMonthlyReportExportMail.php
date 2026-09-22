<?php

namespace App\Mail;

use App\Enums\CaMonthlyReportEmailDeliveryMode;
use App\Models\CaMonthlyReportExport;
use App\Services\Finance\CaMonthlyReportExportStorage;
use App\Services\SupportContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CaMonthlyReportExportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly CaMonthlyReportExport $export,
        private readonly CaMonthlyReportEmailDeliveryMode $deliveryMode,
        private readonly ?string $downloadUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CA Monthly Report '.$this->export->dateRangeLabel(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ca-monthly-report-export',
            with: app(SupportContactResolver::class)->mergeIntoVariables([
                'export' => $this->export,
                'deliveryMode' => $this->deliveryMode,
                'downloadUrl' => $this->downloadUrl,
            ]),
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->deliveryMode !== CaMonthlyReportEmailDeliveryMode::Attachment) {
            return [];
        }

        $storage = app(CaMonthlyReportExportStorage::class);

        return [
            Attachment::fromPath($storage->absolutePath($this->export))
                ->as($this->export->downloadFilename())
                ->withMime($this->export->format->mimeType()),
        ];
    }
}
