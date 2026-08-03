<?php

namespace App\Services\Administration;

use App\Services\Operations\OperationsGmailHealthService;
use App\Services\SystemSettingsService;
use App\Services\VersionService;
use Illuminate\Support\Facades\Config;

/**
 * Configuration-presence summary for System Settings Overview.
 *
 * Intentionally does not probe runtime health (queue, scheduler, CPU, etc.).
 * Those belong on Platform Mission Control.
 */
class ConfigurationHealthSummaryService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly OperationsGmailHealthService $gmailHealth,
        private readonly VersionService $versionService,
    ) {}

    /**
     * @return array{
     *     items: list<array{key: string, label: string, configured: bool, status_label: string, status: string, hint: string}>,
     *     environment: string,
     *     version: string,
     *     build: string|null,
     *     platform_url: string,
     *     platform_integrations_url: string,
     *     platform_tools_url: string
     * }
     */
    public function summary(): array
    {
        $build = $this->versionService->build();

        return [
            'items' => [
                $this->item(
                    key: 'cashfree',
                    label: 'Cashfree',
                    configured: filled(Config::get('cashfree.client_secret')),
                    configuredHint: 'Client secret is present.',
                    missingHint: 'CASHFREE_CLIENT_SECRET is not set.',
                ),
                $this->item(
                    key: 'gmail',
                    label: 'Gmail',
                    configured: $this->gmailConfigured(),
                    configuredHint: 'Inbound Gmail sync is enabled with mailboxes.',
                    missingHint: 'Inbound Gmail sync is not configured.',
                ),
                $this->item(
                    key: 'telegram',
                    label: 'Telegram',
                    configured: $this->systemSettings->getBool('telegram.api_enabled', false),
                    configuredHint: 'Telegram API is enabled in system settings.',
                    missingHint: 'Telegram API is disabled or not configured.',
                ),
                $this->item(
                    key: 'interakt',
                    label: 'Interakt',
                    configured: filled(Config::get('interakt.api_key')),
                    configuredHint: 'Interakt API key is present.',
                    missingHint: 'INTERAKT_API_KEY is not set.',
                ),
                $this->item(
                    key: 'meta',
                    label: 'Meta',
                    configured: false,
                    configuredHint: 'Meta WhatsApp Flow is configured.',
                    missingHint: 'Meta WhatsApp Flow is not yet configured.',
                ),
                $this->item(
                    key: 'smtp',
                    label: 'SMTP',
                    configured: $this->smtpConfigured(),
                    configuredHint: 'Mail driver is configured for delivery.',
                    missingHint: 'Mail is using log driver or is disabled.',
                ),
            ],
            'environment' => (string) Config::get('app.env', 'production'),
            'version' => $this->versionService->version(),
            'build' => $build !== null && $build !== '' ? $build : null,
            'platform_url' => route('admin.platform.index'),
            'platform_integrations_url' => route('admin.platform.index').'#platform-zone-integration_health',
            'platform_tools_url' => route('admin.platform.index').'#platform-zone-tools',
        ];
    }

    private function gmailConfigured(): bool
    {
        if (! Config::get('inbound_email.enabled') || ! Config::get('inbound_email.gmail.enabled')) {
            return false;
        }

        return $this->gmailHealth->configuredMailboxes() !== [];
    }

    private function smtpConfigured(): bool
    {
        return (bool) Config::get('mail.enabled', true)
            && Config::get('mail.default') !== 'log';
    }

    /**
     * @return array{key: string, label: string, configured: bool, status_label: string, status: string, hint: string}
     */
    private function item(
        string $key,
        string $label,
        bool $configured,
        string $configuredHint,
        string $missingHint,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'configured' => $configured,
            'status_label' => $configured ? 'Configured' : 'Not configured',
            'status' => $configured ? 'success' : 'warning',
            'hint' => $configured ? $configuredHint : $missingHint,
        ];
    }
}
