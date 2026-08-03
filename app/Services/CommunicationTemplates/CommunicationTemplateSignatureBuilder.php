<?php

namespace App\Services\CommunicationTemplates;

use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Models\User;

class CommunicationTemplateSignatureBuilder
{
    public function companyDefault(?string $companyName = null): string
    {
        $company = trim((string) ($companyName ?: config('communication_actions.company_name', 'Radium')));

        return '<p>Kind regards,<br><br>Team '.e($company).'</p>';
    }

    public function forUser(?User $user, ?string $companyFallback = null): string
    {
        if (! $user instanceof User) {
            return $this->companyDefault($companyFallback);
        }

        $company = trim((string) ($user->company_name ?: $companyFallback ?: config('communication_actions.company_name', 'Radium')));
        $lines = array_values(array_filter([
            trim((string) $user->name),
            trim((string) ($user->designation ?? '')),
            trim((string) ($user->department ?? '')),
            trim((string) ($user->phone ?? '')),
            trim((string) ($user->email ?? '')),
            $company !== '' ? $company : null,
        ], fn (?string $line): bool => $line !== null && $line !== ''));

        if ($lines === []) {
            return $this->companyDefault($company);
        }

        return '<p>Kind regards,<br><br>'.implode('<br>', array_map('e', $lines)).'</p>';
    }

    public function render(
        CommunicationTemplateSignatureMode $mode,
        ?User $user = null,
        ?string $companyName = null,
    ): string {
        return match ($mode) {
            CommunicationTemplateSignatureMode::None => '',
            CommunicationTemplateSignatureMode::CompanyDefault => $this->companyDefault($companyName),
            CommunicationTemplateSignatureMode::UserSignature => $this->forUser($user, $companyName),
        };
    }

    public function resolveGreeting(
        ?CommunicationTemplateGreetingStyle $style,
        ?User $user,
        array $variables,
    ): CommunicationTemplateGreetingStyle {
        if ($style instanceof CommunicationTemplateGreetingStyle && $style !== CommunicationTemplateGreetingStyle::CompanyDefault) {
            return $style;
        }

        $fromProfile = CommunicationTemplateGreetingStyle::tryFrom((string) ($user?->default_greeting_style ?? ''));

        return $fromProfile ?? CommunicationTemplateGreetingStyle::HelloCustomer;
    }
}
