<?php

namespace App\Services;

class SupportContactConfiguration
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function email(): string
    {
        return $this->resolveValue('support.email', 'support_contact.email');
    }

    public function phone(): string
    {
        return $this->resolveValue('support.phone', 'support_contact.phone');
    }

    public function whatsapp(): string
    {
        return $this->resolveValue('support.whatsapp', 'support_contact.whatsapp');
    }

    public function website(): string
    {
        return $this->resolveValue('support.website', 'support_contact.website');
    }

    public function legacyContact(): string
    {
        return $this->resolveValue('support.contact', 'support_contact.contact');
    }

    public function applyToConfig(): void
    {
        $values = [
            'email' => $this->email(),
            'phone' => $this->phone(),
            'whatsapp' => $this->whatsapp(),
            'website' => $this->website(),
            'contact' => $this->legacyContact(),
        ];

        config([
            'support_contact' => array_merge((array) config('support_contact', []), $values),
            'communication_actions.support_email' => $values['email'],
            'communication_actions.support_phone' => $values['phone'],
            'communication_actions.support_whatsapp' => $values['whatsapp'],
            'communication_actions.support_website' => $values['website'],
            'communication_actions.support_contact' => $values['contact'],
        ]);
    }

    private function resolveValue(string $settingKey, string $configKey): string
    {
        $fromSettings = $this->settingService->get($settingKey);

        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return trim($fromSettings);
        }

        return trim((string) config($configKey, ''));
    }
}
