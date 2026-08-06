<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;

/**
 * Moves human-owned spam-queue emails back to Needs Review.
 * Spam is for ignored junk only — never for assigned/worked mail.
 */
class IncomingEmailSpamRecoveryService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isSpamIgnored(IncomingEmailMessage $message): bool
    {
        if ($message->status !== IncomingEmailMessageStatus::Ignored) {
            return false;
        }

        if ($message->classification === IncomingEmailClassification::Spam) {
            return true;
        }

        return in_array((string) $message->ignore_reason, ['spam', 'trash'], true);
    }

    /**
     * @return bool True when the message was restored.
     */
    public function restoreToNeedsReview(IncomingEmailMessage $message, User $actor): bool
    {
        if (! $this->isSpamIgnored($message)) {
            return false;
        }

        $message->update([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'ignore_reason' => null,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'disposition' => null,
            'disposition_reason' => null,
            'disposed_at' => null,
            'disposed_by_user_id' => null,
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.spam_restored_to_needs_review',
            auditable: $message->fresh(),
            newValues: [
                'status' => IncomingEmailMessageStatus::NeedsReview->value,
                'reason' => 'Operator worked a spam-queue email.',
            ],
        );

        return true;
    }
}
