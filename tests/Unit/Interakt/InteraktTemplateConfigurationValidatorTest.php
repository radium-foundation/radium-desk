<?php

namespace Tests\Unit\Interakt;

use App\Enums\OperationsHealthStatus;
use App\Enums\WhatsAppTemplate;
use App\Services\Interakt\InteraktTemplateConfigurationValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InteraktTemplateConfigurationValidatorTest extends TestCase
{
    private InteraktTemplateConfigurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(InteraktTemplateConfigurationValidator::class);
        $this->configureTemplates();
    }

    public function test_missing_template_name_is_reported_as_error(): void
    {
        Config::set('interakt.templates.request_serial_number.name', '');

        $status = $this->validator->validateTemplate('request_serial_number');

        $this->assertFalse($status->valid);
        $this->assertSame('Template name missing', $status->error);
        $this->assertNull($status->warning);
    }

    public function test_missing_language_fallback_is_reported_as_warning(): void
    {
        Config::set('interakt.templates.request_serial_number.language_code', 'en');
        Config::set('interakt.templates.request_serial_number.language_code_is_default', true);

        $status = $this->validator->validateTemplate('request_serial_number');

        $this->assertFalse($status->valid);
        $this->assertNull($status->error);
        $this->assertSame('Language uses fallback "en"', $status->warning);
    }

    public function test_explicit_en_us_is_valid_when_api_key_configured(): void
    {
        Config::set('interakt.templates.request_serial_number.name', 'order_confirm_manual_schedule');
        Config::set('interakt.templates.request_serial_number.language_code', 'en_US');
        Config::set('interakt.templates.request_serial_number.language_code_is_default', false);

        $status = $this->validator->validateTemplate('request_serial_number');

        $this->assertTrue($status->valid);
        $this->assertSame('order_confirm_manual_schedule', $status->templateName);
        $this->assertSame('en_US', $status->languageCode);
        $this->assertNull($status->warning);
        $this->assertNull($status->error);
    }

    public function test_fallback_en_detection_marks_template_invalid(): void
    {
        Config::set('interakt.templates.request_serial_number.language_code', 'en');
        Config::set('interakt.templates.request_serial_number.language_code_is_default', true);

        $diagnostics = $this->validator->diagnosticsFor(WhatsAppTemplate::RequestSerialNumber);

        $this->assertSame('order_update_request_serial', $diagnostics['template_name']);
        $this->assertSame('en', $diagnostics['language_code']);
        $this->assertSame('Language uses fallback "en"', $diagnostics['language_fallback_warning']);
    }

    public function test_missing_api_key_makes_health_summary_critical(): void
    {
        Config::set('interakt.api_key', null);

        $summary = $this->validator->healthSummary();

        $this->assertSame(OperationsHealthStatus::Failed, $summary['status']);
        $this->assertSame('Critical', $summary['status_label']);
        $this->assertSame('Interakt API key is not configured', $summary['detail']);
    }

    public function test_health_summary_reports_all_templates_configured_when_valid(): void
    {
        foreach (array_keys($this->templateDefaults()) as $templateKey) {
            Config::set('interakt.templates.'.$templateKey.'.language_code', 'en_US');
            Config::set('interakt.templates.'.$templateKey.'.language_code_is_default', false);
        }

        $summary = $this->validator->healthSummary();

        $this->assertSame(OperationsHealthStatus::Healthy, $summary['status']);
        $this->assertSame('7 / 7 templates configured', $summary['detail']);
        $this->assertSame([], $summary['warnings']);
        $this->assertSame([], $summary['errors']);
    }

    public function test_health_summary_reports_warning_for_fallback_language(): void
    {
        Config::set('interakt.templates.request_serial_number.language_code_is_default', true);

        $summary = $this->validator->healthSummary();

        $this->assertSame(OperationsHealthStatus::Warning, $summary['status']);
        $this->assertStringContainsString('request_serial_number', $summary['detail']);
        $this->assertStringContainsString('Language uses fallback "en"', $summary['detail']);
    }

    public function test_diagnostics_report_missing_template(): void
    {
        Config::set('interakt.templates.request_serial_number.name', '');

        $diagnostics = $this->validator->diagnosticsFor(WhatsAppTemplate::RequestSerialNumber);

        $this->assertTrue($diagnostics['template_missing']);
        $this->assertNull($diagnostics['template_name']);
    }

    public function test_log_validation_summary_once_never_logs_api_keys(): void
    {
        Cache::flush();
        Log::spy();

        Config::set('interakt.api_key', 'super-secret-key');

        $this->validator->logValidationSummaryOnce();
        $this->validator->logValidationSummaryOnce();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Interakt template configuration validated', \Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return ! str_contains((string) $encoded, 'super-secret-key')
                    && array_key_exists('configured_templates', $context)
                    && array_key_exists('warnings', $context)
                    && array_key_exists('errors', $context);
            }));
    }

    /**
     * @return array<string, array{name: string, language_code: string, language_code_is_default: bool}>
     */
    private function templateDefaults(): array
    {
        return [
            'request_serial_number' => [
                'name' => 'order_update_request_serial',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'repair_started' => [
                'name' => 'repair_started',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'repair_completed' => [
                'name' => 'repair_completed',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'ready_for_dispatch' => [
                'name' => 'ready_for_dispatch',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'refund_update' => [
                'name' => 'refund_update',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'amc_reminder' => [
                'name' => 'amc_reminder',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
            'support_appointment_booked' => [
                'name' => 'support_appointment_booked',
                'language_code' => 'en_US',
                'language_code_is_default' => false,
            ],
        ];
    }

    private function configureTemplates(): void
    {
        Config::set('interakt.api_key', 'test-interakt-key');

        foreach ($this->templateDefaults() as $templateKey => $templateConfig) {
            Config::set('interakt.templates.'.$templateKey, $templateConfig);
        }
    }
}
