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
            'order_id' => $incident->order_id,
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

    public function promoteToServiceCase(
        Incident $incident,
        IncomingEmailMessage $message,
        User $actor,
    ): IncomingEmailMessage {
        if ($message->incident_id !== null) {
            if ((int) $message->incident_id === (int) $incident->id) {
                return $message->fresh();
            }

            throw new \RuntimeException(
                'Incoming email message is already linked to a different service case.',
            );
        }

        if ($message->status !== IncomingEmailMessageStatus::HistoricalCustomer) {
            throw new \RuntimeException(
                'Incoming email message is not eligible for promotion to a service case.',
            );
        }

        if ($message->order_id !== null && (int) $message->order_id !== (int) $incident->order_id) {
            throw new \RuntimeException(
                'Incoming email message does not belong to this service case order.',
            );
        }

        $this->createLink($incident, $message);

        $message->update([
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'order_id' => $message->order_id ?? $incident->order_id,
            'ignore_reason' => null,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.promoted_to_service_case',
            auditable: $incident->fresh(),
            newValues: [
                'incoming_email_message_id' => $message->id,
                'order_id' => $message->order_id,
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
