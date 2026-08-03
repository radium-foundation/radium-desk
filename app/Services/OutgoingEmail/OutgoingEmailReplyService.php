<?php

namespace App\Services\OutgoingEmail;

use App\Enums\OutgoingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OutgoingEmailReplyService
{
    public function __construct(
        private readonly OutgoingEmailReplyGate $replyGate,
        private readonly OutgoingEmailMimeBuilder $mimeBuilder,
        private readonly OutgoingEmailTemplatePreviewService $templatePreviewService,
        private readonly GmailApiClient $gmailApiClient,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{
     *     can_reply: bool,
     *     reason: ?string,
     *     to_email: ?string,
     *     default_subject: ?string,
     *     mailbox: ?string,
     *     templates: list<array{key: string, label: string}>
     * }
     */
    public function context(User $user, IncomingEmailMessage $message): array
    {
        $gate = $this->replyGate->evaluate($user, $message);

        return [
            'can_reply' => $gate['allowed'],
            'reason' => $gate['reason'],
            'to_email' => $message->from_email,
            'default_subject' => $this->templatePreviewService->defaultReplySubject($message),
            'mailbox' => $message->mailbox,
            'templates' => $gate['allowed']
                ? $this->templatePreviewService->availableTemplates($user)
                : [],
        ];
    }

    /**
     * @return array{subject: string, body_html: string, template_key: string}
     */
    public function previewTemplate(User $user, IncomingEmailMessage $message, string $templateKey): array
    {
        $this->assertCanReply($user, $message);

        return $this->templatePreviewService->preview($message, $templateKey, $user);
    }

    /**
     * @return OutgoingEmailMessage
     */
    public function send(
        User $user,
        IncomingEmailMessage $message,
        string $subject,
        string $bodyHtml,
        ?string $templateKey = null,
    ): OutgoingEmailMessage {
        $this->assertCanReply($user, $message);

        $subject = trim($subject);
        $bodyHtml = trim($bodyHtml);

        if ($subject === '') {
            throw new RuntimeException('Reply subject is required.');
        }

        if ($bodyHtml === '' || trim(strip_tags($bodyHtml)) === '') {
            throw new RuntimeException('Reply body is required.');
        }

        $mailbox = strtolower(trim((string) $message->mailbox));
        $toEmail = strtolower(trim((string) $message->from_email));
        $bodyText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $preview = Str::limit($bodyText, 280, '…');

        $message->loadMissing('incident');

        $outgoing = OutgoingEmailMessage::query()->create([
            'in_reply_to_incoming_email_message_id' => $message->id,
            'incident_id' => $message->incident_id,
            'order_id' => $message->order_id ?? $message->incident?->order_id,
            'mailbox' => $mailbox,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText !== '' ? $bodyText : null,
            'preview' => $preview,
            'thread_id' => $message->thread_id,
            'template_key' => $templateKey !== null && trim($templateKey) !== ''
                ? trim($templateKey)
                : null,
            'sent_by_user_id' => $user->id,
            'status' => OutgoingEmailMessageStatus::Queued,
            'provider' => 'gmail',
        ]);

        try {
            $raw = $this->mimeBuilder->buildRawBase64Url(
                fromEmail: $mailbox,
                toEmail: $toEmail,
                subject: $subject,
                bodyHtml: $bodyHtml,
                inReplyToMessageId: $message->rfc_message_id,
            );

            $generatedMessageId = $this->mimeBuilder->extractGeneratedMessageId($raw);

            $response = $this->gmailApiClient->sendRawMessage(
                $mailbox,
                $raw,
                $message->thread_id,
            );

            $outgoing->update([
                'status' => OutgoingEmailMessageStatus::Sent,
                'provider_message_id' => $response['id'],
                'thread_id' => $response['threadId'] !== '' ? $response['threadId'] : $message->thread_id,
                'rfc_message_id' => $generatedMessageId,
                'sent_at' => now(),
                'error' => null,
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'outgoing_email.sent',
                auditable: $outgoing->fresh(),
                newValues: [
                    'mailbox' => $mailbox,
                    'to_email' => $toEmail,
                    'subject' => $subject,
                    'thread_id' => $outgoing->thread_id,
                    'provider_message_id' => $response['id'],
                    'in_reply_to_incoming_email_message_id' => $message->id,
                    'template_key' => $outgoing->template_key,
                ],
            );

            return $outgoing->fresh();
        } catch (Throwable $exception) {
            $outgoing->update([
                'status' => OutgoingEmailMessageStatus::Failed,
                'error' => $exception->getMessage(),
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'outgoing_email.failed',
                auditable: $outgoing->fresh(),
                newValues: [
                    'mailbox' => $mailbox,
                    'to_email' => $toEmail,
                    'subject' => $subject,
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    private function assertCanReply(User $user, IncomingEmailMessage $message): void
    {
        $gate = $this->replyGate->evaluate($user, $message);

        if (! $gate['allowed']) {
            abort(403, 'Email reply is not allowed: '.($gate['reason'] ?? 'denied'));
        }
    }
}
