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

    public function test_redacts_email_phone_and_stack_fragments(): void
    {
        $raw = 'Mint blocked for agent@example.com +919876543210 '
            ."SQLSTATE[HY000] deadlock\n#0 /var/www/vendor/foo.php(12): bar()";

        $sanitized = $this->sanitizer->sanitize($raw);

        $this->assertNotNull($sanitized);
        $this->assertStringNotContainsString('agent@example.com', $sanitized);
        $this->assertStringNotContainsString('9876543210', $sanitized);
        $this->assertStringNotContainsString('/var/www', $sanitized);
    }

    public function test_unsafe_payload_falls_back_to_generic_message(): void
    {
        $this->assertSame(
            'Invoice mint failed. Contact finance operations for details.',
            $this->sanitizer->sanitize('SQLSTATE[42000]: Syntax error near {"payload":true} https://internal/api'),
        );
    }
}
