<?php

namespace App\Enums\CommunicationTemplates;

enum CommunicationTemplateStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Deprecated => 'Deprecated',
        };
    }

    public function isUsableByAgents(): bool
    {
        return $this === self::Approved;
    }
}
