<?php

namespace App\Enums;

enum IncomingEmailLearningScope: string
{
    case ThisEmail = 'this_email';
    case SameSender = 'same_sender';
    case SameDomain = 'same_domain';
    case SameSubjectPattern = 'same_subject_pattern';
    case Always = 'always';

    public function label(): string
    {
        return match ($this) {
            self::ThisEmail => 'This email only',
            self::SameSender => 'Same sender',
            self::SameDomain => 'Same domain',
            self::SameSubjectPattern => 'Same subject pattern',
            self::Always => 'Always',
        };
    }

    public function createsPersistentRule(): bool
    {
        return $this !== self::ThisEmail;
    }

    public function toRuleType(): ?IncomingEmailLearningRuleType
    {
        return match ($this) {
            self::ThisEmail => null,
            self::SameSender => IncomingEmailLearningRuleType::Sender,
            self::SameDomain => IncomingEmailLearningRuleType::SenderDomain,
            self::SameSubjectPattern => IncomingEmailLearningRuleType::SubjectPattern,
            self::Always => IncomingEmailLearningRuleType::Mailbox,
        };
    }
}
