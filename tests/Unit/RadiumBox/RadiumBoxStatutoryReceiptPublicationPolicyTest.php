<?php

namespace Tests\Unit\RadiumBox;

use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RadiumBoxStatutoryReceiptPublicationPolicyTest extends TestCase
{
    #[DataProvider('allowedInvoiceIdParsingProvider')]
    public function test_parse_allowed_invoice_ids_from_env(string $raw, array $expected): void
    {
        $this->assertSame(
            $expected,
            RadiumBoxStatutoryReceiptPublicationPolicy::parseAllowedInvoiceIdsFromEnv($raw),
        );
    }

    public static function allowedInvoiceIdParsingProvider(): array
    {
        return [
            'empty string' => ['', []],
            'whitespace only' => ['   ', []],
            'single id' => ['16543', [16543]],
            'comma separated' => ['16543,16566,16577', [16543, 16566, 16577]],
            'padded tokens' => [' 16543 , 16566 ', [16543, 16566]],
            'duplicates deduped' => ['16543,16543,16543', [16543]],
            'invalid tokens ignored' => ['16543,abc,16566,-1,0,', [16543, 16566]],
            'non positive ignored' => ['0,-5,16543', [16543]],
        ];
    }

    public function test_delivery_blocked_when_publisher_disabled_even_with_allowlist_config(): void
    {
        config([
            'radiumbox.statutory_receipt_publish.enabled' => false,
            'radiumbox.statutory_receipt_publish.allowed_invoice_ids' => [16543],
        ]);

        $invoice = new \App\Models\StatutoryInvoice;
        $invoice->id = 16543;

        $policy = app(RadiumBoxStatutoryReceiptPublicationPolicy::class);

        $this->assertFalse($policy->mayDeliverHttp($invoice));
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationPolicy::DISABLED_MESSAGE,
            $policy->deliveryBlockedReason($invoice),
        );
    }

    public function test_empty_allowlist_allows_any_invoice_when_enabled(): void
    {
        config([
            'radiumbox.statutory_receipt_publish.enabled' => true,
            'radiumbox.statutory_receipt_publish.allowed_invoice_ids' => [],
        ]);

        $invoice = new \App\Models\StatutoryInvoice;
        $invoice->id = 99999;

        $policy = app(RadiumBoxStatutoryReceiptPublicationPolicy::class);

        $this->assertTrue($policy->mayDeliverHttp($invoice));
        $this->assertNull($policy->deliveryBlockedReason($invoice));
    }
}
