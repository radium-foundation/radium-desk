<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxErrorSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceStatutoryInvoiceMintOutboxErrorSanitizerTest extends TestCase
{
    private ServiceStatutoryInvoiceMintOutboxErrorSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new ServiceStatutoryInvoiceMintOutboxErrorSanitizer;
    }

    #[DataProvider('missingServiceCommerceProvider')]
    public function test_maps_missing_service_commerce_to_safe_reason(string $raw): void
    {
        $this->assertSame(
            'Upstream commerce payload is incomplete (service commerce data missing).',
            $this->sanitizer->sanitize($raw),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingServiceCommerceProvider(): array
    {
        return [
            'contract' => ['RadiumBox response missing service_commerce section.'],
            'uppercase' => ['Missing SERVICE_COMMERCE in upstream payload'],
        ];
    }

    public function test_rn387_blocked_issuance_without_order_or_channel_leakage(): void
    {
        $raw = 'Automatic statutory invoice issuance for RN387 (rdservice_net) is blocked: no linked commerce order.';

        $sanitized = $this->sanitizer->sanitize($raw);

        $this->assertSame('Statutory invoice mint requires a linked commerce order.', $sanitized);
        $this->assertStringNotContainsString('RN387', $sanitized);
        $this->assertStringNotContainsString('rdservice_net', $sanitized);
        $this->assertStringNotContainsString('Automatic', $sanitized);
    }

    public function test_missing_linked_commerce_order_maps_to_allowlisted_message(): void
    {
        $this->assertSame(
            'Statutory invoice mint requires a linked commerce order.',
            $this->sanitizer->sanitize('RN600: no linked commerce order for statutory mint.'),
        );
    }

    public function test_unknown_error_returns_generic_message(): void
    {
        $this->assertSame(
            ServiceStatutoryInvoiceMintOutboxErrorSanitizer::GENERIC_FAILURE_MESSAGE,
            $this->sanitizer->sanitize('Unexpected upstream fault from provider widget 9912.'),
        );
    }

    #[DataProvider('sensitiveUnknownProvider')]
    public function test_unknown_sensitive_payload_never_leaks_to_ui(string $raw): void
    {
        $sanitized = $this->sanitizer->sanitize($raw);

        $this->assertSame(ServiceStatutoryInvoiceMintOutboxErrorSanitizer::GENERIC_FAILURE_MESSAGE, $sanitized);
        $this->assertStringNotContainsString('agent@example.com', $sanitized);
        $this->assertStringNotContainsString('9876543210', $sanitized);
        $this->assertStringNotContainsString('/var/www', $sanitized);
        $this->assertStringNotContainsString('https://', $sanitized);
        $this->assertStringNotContainsString('bearer', $sanitized);
        $this->assertStringNotContainsString('SQLSTATE', $sanitized);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sensitiveUnknownProvider(): array
    {
        return [
            'email_phone_stack' => [
                'Mint blocked for agent@example.com +919876543210 via https://internal.host/api #0 /var/www/vendor/foo.php(12): bar()',
            ],
            'json_url_token' => [
                'SQLSTATE[42000]: Syntax error near {"payload":true} https://internal/api bearer abc.def.ghi',
            ],
        ];
    }

    public function test_transient_infrastructure_maps_to_allowlisted_message(): void
    {
        $this->assertSame(
            'Temporary infrastructure issue during invoice mint.',
            $this->sanitizer->sanitize('SQLSTATE[40001]: Deadlock found when trying to get lock'),
        );
    }

    public function test_explicit_approval_block_maps_to_allowlisted_message(): void
    {
        $this->assertSame(
            'Automatic statutory invoice issuance is blocked until the order is explicitly approved.',
            $this->sanitizer->sanitize('RN501 blocked until explicit per-order approval for rdservice_net channel.'),
        );
    }
}
