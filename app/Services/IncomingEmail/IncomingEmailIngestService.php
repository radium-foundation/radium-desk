<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailMessage;
use App\Models\OutgoingEmailMessage;
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

        $previewMax = max(1, (int) config('inbound_email.preview_max_chars', 500));
        $preview = $dto->preview !== null
            ? Str::limit(trim($dto->preview), $previewMax, '…')
            : null;

        $channel = $dto->channel
            ?? $this->resolveMailboxChannel($dto->mailbox);

        $rawPayload = $dto->rawPayload ?? [];

        if ($dto->attachments !== []) {
            $rawPayload['attachments'] = $dto->attachments;
        }

        $isOwnOutbound = $this->isOwnOutboundEcho($dto);

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
            'status' => $isOwnOutbound
                ? IncomingEmailMessageStatus::Ignored
                : IncomingEmailMessageStatus::Received,
            'ignore_reason' => $isOwnOutbound ? 'own_outbound' : null,
            'classification' => $isOwnOutbound ? IncomingEmailClassification::OwnOutbound : null,
            'processed_at' => $isOwnOutbound ? now() : null,
        ]);

        $actor = $this->automationIdentity->systemUser();

        $this->auditLogService->log(
            userId: $actor->id,
            event: $isOwnOutbound ? 'incoming_email.ignored' : 'incoming_email.received',
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
                'reason' => $isOwnOutbound ? 'own_outbound' : null,
                'classification' => $isOwnOutbound ? IncomingEmailClassification::OwnOutbound->value : null,
            ],
        );

        if ($isOwnOutbound) {
            IncomingEmailIgnoreStat::incrementReason('own_outbound');

            return $message->fresh();
        }

        $this->outboxWriter->writeProcessingJob($message->id);

        if ($processImmediately) {
            $this->outboxProcessorService->processAggregate(
                IncomingEmailOutboxWriter::AGGREGATE_TYPE,
                $message->id,
            );
        }

        return $message->fresh();
    }

    /**
     * Own outbound must never reopen closed cases or enter intake processing.
     *
     * Match when ANY of: Gmail SENT label, From ∈ configured Radium mailboxes,
     * OutgoingEmailMessage by provider id, or OutgoingEmailMessage by RFC Message-ID.
     */
    private function isOwnOutboundEcho(NormalizedInboundEmail $dto): bool
    {
        if ($this->hasSentLabel($dto->labels)) {
            return true;
        }

        $fromEmail = strtolower(trim($dto->fromEmail));

        if ($fromEmail !== '' && in_array($fromEmail, $this->configuredRadiumMailboxEmails(), true)) {
            return true;
        }

        $providerMessageId = $dto->providerMessageId !== null ? trim($dto->providerMessageId) : '';

        if ($providerMessageId !== '') {
            $exists = OutgoingEmailMessage::query()
                ->where('provider_message_id', $providerMessageId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        $rfcMessageId = $this->normalizeMessageId($dto->rfcMessageId);

        if ($rfcMessageId !== null) {
            return OutgoingEmailMessage::query()
                ->where('rfc_message_id', $rfcMessageId)
                ->exists();
        }

        return false;
    }

    /**
     * @param  list<string>  $labels
     */
    private function hasSentLabel(array $labels): bool
    {
        foreach ($labels as $label) {
            if (strtoupper(trim((string) $label)) === 'SENT') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function configuredRadiumMailboxEmails(): array
    {
        $emails = [];

        foreach (array_keys(config('inbound_email.mailboxes', [])) as $email) {
            $emails[] = strtolower(trim((string) $email));
        }

        foreach (config('inbound_email.gmail.sync_mailboxes', []) as $email) {
            $emails[] = strtolower(trim((string) $email));
        }

        foreach (config('inbound_email.reply.mailboxes', []) as $email) {
            $emails[] = strtolower(trim((string) $email));
        }

        return array_values(array_unique(array_filter(
            $emails,
            static fn (string $email): bool => $email !== '',
        )));
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
