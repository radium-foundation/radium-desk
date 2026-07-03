<?php

namespace App\Services\Interakt;

use App\Data\Interakt\TemplateConfigurationStatus;
use App\Enums\OperationsHealthStatus;
use App\Enums\WhatsAppTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class InteraktTemplateConfigurationValidator
{
    public const DEFAULT_LANGUAGE = 'en';

    /**
     * @var array<string, array{name: string, language: string}>
     */
    private const TEMPLATE_ENV_KEYS = [
        'request_serial_number' => [
            'name' => 'INTERAKT_TEMPLATE_REQUEST_SERIAL',
            'language' => 'INTERAKT_TEMPLATE_REQUEST_SERIAL_LANGUAGE',
        ],
        'repair_started' => [
            'name' => 'INTERAKT_TEMPLATE_REPAIR_STARTED',
            'language' => 'INTERAKT_TEMPLATE_REPAIR_STARTED_LANGUAGE',
        ],
        'repair_completed' => [
            'name' => 'INTERAKT_TEMPLATE_REPAIR_COMPLETED',
            'language' => 'INTERAKT_TEMPLATE_REPAIR_COMPLETED_LANGUAGE',
        ],
        'ready_for_dispatch' => [
            'name' => 'INTERAKT_TEMPLATE_READY_FOR_DISPATCH',
            'language' => 'INTERAKT_TEMPLATE_READY_FOR_DISPATCH_LANGUAGE',
        ],
        'refund_update' => [
            'name' => 'INTERAKT_TEMPLATE_REFUND_UPDATE',
            'language' => 'INTERAKT_TEMPLATE_REFUND_UPDATE_LANGUAGE',
        ],
        'amc_reminder' => [
            'name' => 'INTERAKT_TEMPLATE_AMC_REMINDER',
            'language' => 'INTERAKT_TEMPLATE_AMC_REMINDER_LANGUAGE',
        ],
        'support_appointment_booked' => [
            'name' => 'INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED',
            'language' => 'INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED_LANGUAGE',
        ],
    ];

    /**
     * @return list<TemplateConfigurationStatus>
     */
    public function validateAll(): array
    {
        $apiKeyConfigured = filled(Config::get('interakt.api_key'));
        $statuses = [];

        foreach (array_keys(self::TEMPLATE_ENV_KEYS) as $templateKey) {
            $statuses[] = $this->validateTemplate($templateKey, $apiKeyConfigured);
        }

        return $statuses;
    }

    public function validateTemplate(string $templateKey, ?bool $apiKeyConfigured = null): TemplateConfigurationStatus
    {
        $apiKeyConfigured ??= filled(Config::get('interakt.api_key'));

        /** @var array<string, mixed>|null $config */
        $config = Config::get('interakt.templates.'.$templateKey);

        if (! is_array($config)) {
            return new TemplateConfigurationStatus(
                templateKey: $templateKey,
                templateName: null,
                languageCode: null,
                valid: false,
                error: 'Template configuration is missing.',
            );
        }

        $templateName = trim((string) ($config['name'] ?? ''));
        $languageCode = trim((string) ($config['language_code'] ?? '')) ?: self::DEFAULT_LANGUAGE;
        $usesDefaultLanguage = $this->usesImplicitLanguageDefault($templateKey, $config, $languageCode);

        $error = null;
        $warning = null;

        if ($templateName === '') {
            $error = 'Template name missing';
        } elseif ($usesDefaultLanguage) {
            $warning = sprintf('Language uses fallback "%s"', self::DEFAULT_LANGUAGE);
        }

        $valid = $error === null
            && $warning === null
            && $apiKeyConfigured;

        return new TemplateConfigurationStatus(
            templateKey: $templateKey,
            templateName: $templateName !== '' ? $templateName : null,
            languageCode: $languageCode !== '' ? $languageCode : null,
            valid: $valid,
            warning: $warning,
            error: $error,
        );
    }

    /**
     * @return array{
     *     status: OperationsHealthStatus,
     *     status_label: string,
     *     detail: string,
     *     configured_count: int,
     *     total_count: int,
     *     warnings: list<string>,
     *     errors: list<string>
     * }
     */
    public function healthSummary(): array
    {
        $statuses = $this->validateAll();
        $totalCount = count($statuses);
        $configuredCount = count(array_filter(
            $statuses,
            fn (TemplateConfigurationStatus $status): bool => filled($status->templateName),
        ));
        $warnings = [];
        $errors = [];

        if (! filled(Config::get('interakt.api_key'))) {
            $errors[] = 'Interakt API key is not configured';
        }

        foreach ($statuses as $status) {
            if ($status->error !== null) {
                $errors[] = $this->formatIssue($status, $status->error);
            }

            if ($status->warning !== null) {
                $warnings[] = $this->formatIssue($status, $status->warning);
            }
        }

        if ($errors !== []) {
            return [
                'status' => OperationsHealthStatus::Failed,
                'status_label' => 'Critical',
                'detail' => $errors[0],
                'configured_count' => $configuredCount,
                'total_count' => $totalCount,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        if ($warnings !== []) {
            return [
                'status' => OperationsHealthStatus::Warning,
                'status_label' => OperationsHealthStatus::Warning->label(),
                'detail' => $warnings[0],
                'configured_count' => $configuredCount,
                'total_count' => $totalCount,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'status' => OperationsHealthStatus::Healthy,
            'status_label' => OperationsHealthStatus::Healthy->label(),
            'detail' => sprintf('%d / %d templates configured', $configuredCount, $totalCount),
            'configured_count' => $configuredCount,
            'total_count' => $totalCount,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{
     *     template_name: ?string,
     *     language_code: ?string,
     *     template_missing: bool,
     *     language_fallback_warning: ?string,
     *     error: ?string
     * }
     */
    public function diagnosticsFor(WhatsAppTemplate|string $template): array
    {
        $templateKey = $template instanceof WhatsAppTemplate ? $template->value : $template;
        $status = $this->validateTemplate($templateKey);

        return [
            'template_name' => $status->templateName,
            'language_code' => $status->languageCode,
            'template_missing' => $status->error !== null && str_contains((string) $status->error, 'Template name missing'),
            'language_fallback_warning' => $status->warning,
            'error' => $status->error,
        ];
    }

    public function logValidationSummaryOnce(): void
    {
        $signature = $this->configurationSignature();
        $cacheKey = 'interakt.template.configuration.validated.'.$signature;

        if (Cache::has($cacheKey)) {
            return;
        }

        $summary = $this->healthSummary();
        $statuses = $this->validateAll();

        Log::info('Interakt template configuration validated', [
            'configured_templates' => collect($statuses)
                ->filter(fn (TemplateConfigurationStatus $status): bool => filled($status->templateName))
                ->mapWithKeys(fn (TemplateConfigurationStatus $status): array => [
                    $status->templateKey => [
                        'template_name' => $status->templateName,
                        'language_code' => $status->languageCode,
                    ],
                ])
                ->all(),
            'warnings' => $summary['warnings'],
            'errors' => $summary['errors'],
        ]);

        Cache::forever($cacheKey, true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function usesImplicitLanguageDefault(string $templateKey, array $config, string $languageCode): bool
    {
        if (array_key_exists('language_code_is_default', $config)) {
            return (bool) $config['language_code_is_default'];
        }

        if ($languageCode !== self::DEFAULT_LANGUAGE) {
            return false;
        }

        $languageEnvKey = self::TEMPLATE_ENV_KEYS[$templateKey]['language'] ?? null;

        if ($languageEnvKey === null) {
            return true;
        }

        $explicitLanguage = env($languageEnvKey);

        return ! filled($explicitLanguage);
    }

    private function formatIssue(TemplateConfigurationStatus $status, string $message): string
    {
        if (str_contains($message, 'API key')) {
            return $message;
        }

        return sprintf('Template "%s": %s', $status->templateKey, $message);
    }

    private function configurationSignature(): string
    {
        $cachedConfigPath = base_path('bootstrap/cache/config.php');

        if (is_file($cachedConfigPath)) {
            return 'cached:'.filemtime($cachedConfigPath);
        }

        return 'runtime:'.md5(json_encode(Config::get('interakt.templates')));
    }
}
