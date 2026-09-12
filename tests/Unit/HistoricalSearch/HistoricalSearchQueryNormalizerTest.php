<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalCanonicalEmailNormalizer;
use App\Services\HistoricalSearch\HistoricalSearchQueryNormalizer;
use PHPUnit\Framework\TestCase;

class HistoricalSearchQueryNormalizerTest extends TestCase
{
    public function test_normalizes_email_and_phone_queries(): void
    {
        $normalizer = new HistoricalSearchQueryNormalizer(new HistoricalCanonicalEmailNormalizer);

        $email = $normalizer->normalize(' Agent@Example.com ');
        $this->assertSame('agent@example.com', $email['email']);
        $this->assertTrue($email['looks_like_email']);

        $phone = $normalizer->normalize('+91 98765 43210');
        $this->assertSame('919876543210', $phone['phone']);
        $this->assertTrue($phone['looks_like_phone']);
    }

    public function test_detects_name_prefix_for_customer_search(): void
    {
        $normalizer = new HistoricalSearchQueryNormalizer(new HistoricalCanonicalEmailNormalizer);

        $parsed = $normalizer->normalize('Aditya Sharma');

        $this->assertSame('aditya sharma', $parsed['name_prefix']);
    }
}
