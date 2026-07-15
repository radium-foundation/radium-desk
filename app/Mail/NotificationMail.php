<?php

namespace App\Mail;

use App\Services\SupportContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $viewName,
        private readonly array $variables,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->viewName,
            with: app(SupportContactResolver::class)->mergeIntoVariables($this->variables),
        );
    }
}
