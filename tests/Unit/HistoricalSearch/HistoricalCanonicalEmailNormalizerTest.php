<?php

namespace Tests\Unit\HistoricalSearch;

use App\Services\HistoricalSearch\HistoricalCanonicalEmailNormalizer;
use PHPUnit\Framework\TestCase;

class HistoricalCanonicalEmailNormalizerTest extends TestCase
{
    public function test_strips_leading_grave_accent_from_canonical_defect(): void
    {
        $normalizer = new HistoricalCanonicalEmailNormalizer;

        $this->assertSame(
            'baswaraj744@gmail.com',
            $normalizer->normalizeForSearch('`baswaraj744@gmail.com'),
        );
    }

    public function test_search_variants_include_defect_form_for_lookup(): void
    {
        $normalizer = new HistoricalCanonicalEmailNormalizer;

        $variants = $normalizer->searchVariants('baswaraj744@gmail.com');

        $this->assertContains('baswaraj744@gmail.com', $variants);
        $this->assertContains('`baswaraj744@gmail.com', $variants);
    }

    public function test_valid_email_without_defect_is_unchanged(): void
    {
        $normalizer = new HistoricalCanonicalEmailNormalizer;

        $this->assertSame('user@example.com', $normalizer->normalizeForSearch('User@Example.com'));
        $this->assertContains('user@example.com', $normalizer->searchVariants('user@example.com'));
    }

    public function test_non_email_returns_empty_variants(): void
    {
        $normalizer = new HistoricalCanonicalEmailNormalizer;

        $this->assertSame([], $normalizer->searchVariants('not-an-email'));
    }
}
