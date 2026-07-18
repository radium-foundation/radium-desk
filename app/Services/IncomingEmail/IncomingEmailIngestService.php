<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use App\Models\IncomingEmailMessage;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Support\Str;

class IncomingEmailIngestService
{
    public function __construct(
        private readonly IncomingEmailOutboxWriter $outboxWriter,
        private readonly OutboxProcessorService $outboxProcessorService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function ingest(NormalizedInboundEmail $dto, bool $processImmediately = true): ?IncomingEmailMessage
    {
        if (! config('inbound_email.enabled')) {
            return null;
        }

        $existing = $this->findExisting($dto);

        if ($existing instanceof IncomingEmailMessage) {
            return $existing;
        }

        $previewMax = max(1, (int) config('inbound_email.preview_max_chars', 280));
        $preview = $dto->preview !== null
            ? Str::limit(trim(strip_tags($dto->preview)), $previewMax, '…')
            : null;

        $channel = $dto->channel
            ?? $this->resolveMailboxChannel($dto->mailbox);

        $rawPayload = $dto->rawPayload ?? [];
        $rawPayload['body_text'] = $dto->bodyText;
        $rawPayload['body_html'] = $dto->bodyHtml;
        $rawPayload['attachments'] = $dto->attachments;

        $message = IncomingEmailMessage::query()->create([
            'intake_channel' => IntakeChannel::Email,
            'mailbox' => strtolower(trim($dto->mailbox)),
            'channel' => $channel,
            'provider' => $dto->provider,
            'provider_message_id' => $dto->providerMessageId,
            'rfc_message_id' => $this->normalizeMessageId($dto->rfcMessageId),
            'thread_id' => $dto->threadId,
            'from_email' => strtolower(trim($dto->fromEmail)),
            'from_name' => $dto->fromName !== null ? trim($dto->fromName) : null,
            'to_emails' => array_values(array_map(
                fn (string $email): string => strtolower(trim($email)),
                $dto->toEmails,
            )),
            'subject' => $dto->subject,
            'preview' => $preview,
            'received_at' => $dto->receivedAt,
            'attachment_count' => max(0, $dto->attachmentCount),
            'headers' => $dto->headers,
            'labels' => $dto->labels,
            'raw_payload' => $rawPayload,
            'status' => IncomingEmailMessageStatus::Received,
        ]);

        $actor = $this->automationIdentity->systemUser();

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.received',
            auditable: $message,
            newValues: [
                'mailbox' => $message->mailbox,
                'channel' => $message->channel,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
                'provider_message_id' => $message->provider_message_id,
                'attachment_count' => $message->attachment_count,
            ],
        );

        $this->outboxWriter->writeProcessingJob($message->id);

        if ($processImmediately) {
            $this->outboxProcessorService->processAggregate(
                IncomingEmailOutboxWriter::AGGREGATE_TYPE,
                $message->id,
            );
        }

        return $message->fresh();
    }

    private function findExisting(NormalizedInboundEmail $dto): ?IncomingEmailMessage
    {
        $rfcMessageId = $this->normalizeMessageId($dto->rfcMessageId);

        if ($rfcMessageId !== null) {
            $byRfc = IncomingEmailMessage::query()
                ->where('rfc_message_id', $rfcMessageId)
                ->first();

            if ($byRfc instanceof IncomingEmailMessage) {
                return $byRfc;
            }
        }

        if ($dto->providerMessageId !== null && trim($dto->providerMessageId) !== '') {
            return IncomingEmailMessage::query()
                ->where('provider', $dto->provider)
                ->where('provider_message_id', trim($dto->providerMessageId))
                ->first();
        }

        return null;
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $normalized = trim($messageId);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveMailboxChannel(string $mailbox): ?string
    {
        $mailboxes = config('inbound_email.mailboxes', []);
        $key = strtolower(trim($mailbox));

        if (! is_array($mailboxes)) {
            return null;
        }

        return $mailboxes[$key] ?? null;
    }
}
