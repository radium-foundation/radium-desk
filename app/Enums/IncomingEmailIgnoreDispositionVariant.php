<?php

namespace App\Enums;

enum IncomingEmailIgnoreDispositionVariant: string
{
    case Once = 'ignore_once';
    case AlwaysSender = 'always_ignore_sender';
    case AlwaysDomain = 'always_ignore_domain';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Ignore once',
            self::AlwaysSender => 'Always ignore sender',
            self::AlwaysDomain => 'Always ignore domain',
        };
    }

    public function ignoreReason(): string
    {
        return match ($this) {
            self::Once => 'operator_ignore_once',
            self::AlwaysSender => 'operator_always_ignore_sender',
            self::AlwaysDomain => 'operator_always_ignore_domain',
        };
    }

    public function learningScope(): IncomingEmailLearningScope
    {
        return match ($this) {
            self::Once => IncomingEmailLearningScope::ThisEmail,
            self::AlwaysSender => IncomingEmailLearningScope::SameSender,
            self::AlwaysDomain => IncomingEmailLearningScope::SameDomain,
        };
    }

    public function createsPersistentRule(): bool
    {
        return $this !== self::Once;
    }
}
