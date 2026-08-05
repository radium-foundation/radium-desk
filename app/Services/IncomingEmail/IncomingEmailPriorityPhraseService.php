<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\IncomingEmailPriorityMatch;
use App\Models\AuditLog;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;

class IncomingEmailPriorityPhraseService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function match(IncomingEmailMessage $message): ?IncomingEmailPriorityMatch
    {
        $haystack = $this->haystack($message);

        if ($haystack === '') {
            return null;
        }

        foreach ($this->configuredPhrases() as $phrase) {
            $normalizedPhrase = strtolower(trim($phrase));

            if ($normalizedPhrase !== '' && str_contains($haystack, $normalizedPhrase)) {
                return new IncomingEmailPriorityMatch(
                    matchedPhrase: $phrase,
                    ruleSource: 'config:inbound_email.priority_phrases',
                );
            }
        }

        return null;
    }

    public function matchAndAudit(IncomingEmailMessage $message, ?User $actor = null): ?IncomingEmailPriorityMatch
    {
        $match = $this->match($message);

        if ($match === null || $this->hasAuditForMessage($message)) {
            return $match;
        }

        $actor ??= $this->automationIdentity->systemUser();

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.priority_detected',
            auditable: $message,
            newValues: [
                'incoming_email_message_id' => $message->id,
                'matched_phrase' => $match->matchedPhrase,
                'matched_rule' => $match->matchedPhrase,
                'rule_source' => $match->ruleSource,
                'mailbox' => $message->mailbox,
                'subject' => $message->subject,
                'from_email' => $message->from_email,
                'detected_at' => now()->toIso8601String(),
            ],
        );

        return $match;
    }

    /**
     * @return list<string>
     */
    public function configuredPhrases(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $phrase): string => trim($phrase),
            (array) config('inbound_email.priority_phrases', []),
        ), static fn (string $phrase): bool => $phrase !== ''));
    }

    private function haystack(IncomingEmailMessage $message): string
    {
        return strtolower(trim(
            (string) $message->subject.' '.
            (string) $message->from_email.' '.
            (string) $message->from_name.' '.
            (string) $message->preview
        ));
    }

    private function hasAuditForMessage(IncomingEmailMessage $message): bool
    {
        return AuditLog::query()
            ->where('event', 'incoming_email.priority_detected')
            ->where('new_values->incoming_email_message_id', $message->id)
            ->exists();
    }
}
