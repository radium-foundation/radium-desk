<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;

class IncomingEmailLinkService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function link(Incident $incident, IncomingEmailMessage $message, User $actor): IncomingEmailMessage
    {
        $this->createLink($incident, $message);

        $message->update([
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'ignore_reason' => null,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.linked',
            auditable: $incident->fresh(),
            newValues: [
                'incoming_email_message_id' => $message->id,
                'mailbox' => $message->mailbox,
                'channel' => $message->channel,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
                'provider_message_id' => $message->provider_message_id,
                'attachment_count' => $message->attachment_count,
                'received_at' => $message->received_at?->toIso8601String(),
                'preview' => $message->preview,
            ],
        );

        return $message->fresh();
    }

    private function createLink(Incident $incident, IncomingEmailMessage $message): void
    {
        try {
            IncidentIncomingEmailLink::query()->create([
                'incident_id' => $incident->id,
                'incoming_email_message_id' => $message->id,
                'linked_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateLink($exception)) {
                throw $exception;
            }
        }
    }

    private function isDuplicateLink(QueryException $exception): bool
    {
        $errorCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($errorCode, ['1062', '19', '2067', '1555'], true);
    }
}
