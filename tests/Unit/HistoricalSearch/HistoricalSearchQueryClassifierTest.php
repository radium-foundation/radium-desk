<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalCanonicalEmailNormalizer;
use App\Services\HistoricalSearch\HistoricalSearchQueryClassifier;
use PHPUnit\Framework\TestCase;

class HistoricalSearchQueryClassifierTest extends TestCase
{
    private HistoricalSearchQueryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new HistoricalSearchQueryClassifier(new HistoricalCanonicalEmailNormalizer);
    }

    public function test_invoice_token_uses_token_strategy_only(): void
    {
        $this->assertSame(['token'], $this->classifier->strategies('SELF'));
    }

    public function test_email_uses_email_strategy(): void
    {
        $this->assertSame(['email', 'token'], $this->classifier->strategies('user@example.com'));
    }

    public function test_numeric_product_ref_includes_product_strategy(): void
    {
        $this->assertContains('product', $this->classifier->strategies('4'));
    }

    public function test_awb_uses_awb_strategy(): void
    {
        $this->assertContains('awb', $this->classifier->strategies('1091311852843'));
    }

    public function test_ten_digit_phone_is_not_classified_as_awb(): void
    {
        $strategies = $this->classifier->strategies('0000000000');

        $this->assertContains('phone', $strategies);
        $this->assertNotContains('awb', $strategies);
    }

    public function test_short_name_uses_name_strategy_not_token_only(): void
    {
        $this->assertContains('name', $this->classifier->strategies('admin'));
    }
}
