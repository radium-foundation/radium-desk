<?php

namespace App\Enums\CommunicationTemplates;

enum CommunicationTemplateSignatureMode: string
{
    case CompanyDefault = 'company_default';
    case UserSignature = 'user_signature';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::CompanyDefault => 'Company Default',
            self::UserSignature => 'User Signature',
            self::None => 'No Signature',
        };
    }
}
