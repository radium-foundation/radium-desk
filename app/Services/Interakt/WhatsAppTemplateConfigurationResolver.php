<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppTemplateConfiguration;
use App\Enums\WhatsAppTemplate;
use RuntimeException;

class WhatsAppTemplateConfigurationResolver
{
    public function resolve(WhatsAppTemplate $template): WhatsAppTemplateConfiguration
    {
        /** @var array<string, mixed>|null $config */
        $config = config('interakt.templates.'.$template->value);

        if (! is_array($config)) {
            throw new RuntimeException('WhatsApp template configuration is missing for '.$template->value.'.');
        }

        $name = trim((string) ($config['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('WhatsApp template name is not configured for '.$template->value.'.');
        }

        return new WhatsAppTemplateConfiguration(
            name: $name,
            languageCode: trim((string) ($config['language_code'] ?? 'en')) ?: 'en',
            displayName: trim((string) ($config['display_name'] ?? $name)) ?: $name,
            purpose: trim((string) ($config['purpose'] ?? $template->purposeLabel())) ?: $template->purposeLabel(),
            internalNote: filled($config['internal_note'] ?? null)
                ? trim((string) $config['internal_note'])
                : null,
            bodyParameters: is_array($config['body_parameters'] ?? null) ? $config['body_parameters'] : [],
            headerParameters: is_array($config['header_parameters'] ?? null) ? $config['header_parameters'] : [],
        );
    }
}
