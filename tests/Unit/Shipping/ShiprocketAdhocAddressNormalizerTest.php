<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketAdhocAddressNormalizer;
use App\Services\Shipping\ShiprocketAdhocAddressLimitException;
use PHPUnit\Framework\TestCase;

class ShiprocketAdhocAddressNormalizerTest extends TestCase
{
    private const RDE318516_LINE1 = 'Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research, Deverakaggalahalli, Kanakapura Road,Bengaluru South District, Karnataka - 562 112';

    private const RDE318516_LINE2 = 'BANGALORE KANAKAPURA HIGHWAY, NEAR HAROHALLI';

    public function test_short_address_is_returned_byte_for_byte(): void
    {
        $line1 = '130 SIDDHESWAR PETH WADWAN COMPLEX SOLAPUR';
        $line2 = 'WADWAN COMPLEX COURT LINE';

        [$out1, $out2] = ShiprocketAdhocAddressNormalizer::forPayload(
            $line1,
            $line2,
            'Maharashtra',
            '413006',
        );

        $this->assertSame($line1, $out1);
        $this->assertSame($line2, $out2);
        $this->assertSame(67, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));
    }

    public function test_address_already_at_or_under_190_is_not_normalized(): void
    {
        $line1 = str_repeat('A', 100);
        $line2 = str_repeat('B', 90);

        [$out1, $out2] = ShiprocketAdhocAddressNormalizer::forPayload(
            $line1,
            $line2,
            'Karnataka',
            '562112',
        );

        $this->assertSame($line1, $out1);
        $this->assertSame($line2, $out2);
    }

    public function test_rde318516_strips_trailing_duplicate_state_and_pincode(): void
    {
        $this->assertSame(155, ShiprocketAdhocAddressNormalizer::characterLength(self::RDE318516_LINE1));
        $this->assertSame(44, ShiprocketAdhocAddressNormalizer::characterLength(self::RDE318516_LINE2));
        $this->assertSame(199, ShiprocketAdhocAddressNormalizer::combinedCharacterLength(self::RDE318516_LINE1, self::RDE318516_LINE2));

        [$line1, $line2] = ShiprocketAdhocAddressNormalizer::forPayload(
            self::RDE318516_LINE1,
            self::RDE318516_LINE2,
            'Karnataka',
            '562112',
        );

        $this->assertSame(self::RDE318516_LINE2, $line2);
        $this->assertSame(
            'Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research, Deverakaggalahalli, Kanakapura Road,Bengaluru South District',
            $line1,
        );
        $this->assertLessThanOrEqual(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));
        $this->assertSame(178, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));
        $this->assertStringContainsString('Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research', $line1);
        $this->assertStringContainsString('Deverakaggalahalli', $line1);
        $this->assertStringContainsString('Kanakapura Road', $line1);
        $this->assertStringContainsString('Bengaluru South District', $line1);
        $this->assertStringContainsString('BANGALORE KANAKAPURA HIGHWAY', $line2);
        $this->assertStringContainsString('NEAR HAROHALLI', $line2);
        $this->assertStringNotContainsString('Karnataka', $line1);
        $this->assertStringNotContainsString('562 112', $line1);
        $this->assertStringNotContainsString('562112', $line1);
    }

    public function test_rde318517_uses_the_same_checkout_address_as_rde318516(): void
    {
        [$line1, $line2] = ShiprocketAdhocAddressNormalizer::forPayload(
            self::RDE318516_LINE1,
            self::RDE318516_LINE2,
            'Karnataka',
            '562112',
        );

        $this->assertSame(178, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));
        $this->assertStringContainsString('Bengaluru South District', $line1);
        $this->assertStringContainsString('NEAR HAROHALLI', (string) $line2);
    }

    public function test_over_limit_without_duplicate_suffix_fails_closed(): void
    {
        $this->expectException(ShiprocketAdhocAddressLimitException::class);
        $this->expectExceptionMessage('combined Address 1 + Address 2 limit of 190 characters');

        ShiprocketAdhocAddressNormalizer::forPayload(
            str_repeat('A', 150),
            str_repeat('B', 50),
            'Karnataka',
            '562112',
        );
    }

    public function test_state_inside_meaningful_text_is_not_stripped(): void
    {
        $line1 = 'Karnataka Institute of Medical Education, Deverakaggalahalli, Kanakapura Road, Bengaluru South District Block';
        $line2 = str_repeat('Landmark text without structured suffix ', 3);

        $this->assertGreaterThan(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));
        $this->assertStringContainsString('Karnataka Institute', $line1);

        try {
            ShiprocketAdhocAddressNormalizer::forPayload($line1, $line2, 'Karnataka', '562112');
            $this->fail('Embedded state must not be treated as a removable suffix.');
        } catch (ShiprocketAdhocAddressLimitException) {
            $this->assertStringContainsString('Karnataka Institute', $line1);
        }
    }

    public function test_pincode_inside_meaningful_text_is_not_stripped(): void
    {
        $line1 = 'Plot 562112, Kanakapura Road, Deverakaggalahalli, Bengaluru South District Campus';
        $line2 = str_repeat('Z', 120);

        $this->assertGreaterThan(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));

        try {
            ShiprocketAdhocAddressNormalizer::forPayload($line1, $line2, 'Karnataka', '562112');
            $this->fail('Embedded pincode must not be removed.');
        } catch (ShiprocketAdhocAddressLimitException) {
            $this->assertStringContainsString('Plot 562112', $line1);
        }
    }

    public function test_duplicate_suffix_on_address_2_is_stripped(): void
    {
        $line1 = str_repeat('A', 160);
        $line2 = 'NEAR HAROHALLI, Karnataka - 562 112';

        $this->assertGreaterThan(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($line1, $line2));

        [$out1, $out2] = ShiprocketAdhocAddressNormalizer::forPayload(
            $line1,
            $line2,
            'Karnataka',
            '562112',
        );

        $this->assertSame($line1, $out1);
        $this->assertSame('NEAR HAROHALLI', $out2);
        $this->assertLessThanOrEqual(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($out1, $out2));
    }

    public function test_unicode_length_uses_mb_strlen_not_bytes(): void
    {
        $line1 = str_repeat('क', 190);
        $line2 = null;

        $this->assertSame(190, ShiprocketAdhocAddressNormalizer::characterLength($line1));
        $this->assertGreaterThan(190, strlen($line1));

        [$out1, $out2] = ShiprocketAdhocAddressNormalizer::forPayload(
            $line1,
            $line2,
            'Karnataka',
            '562112',
        );

        $this->assertSame($line1, $out1);
        $this->assertNull($out2);
    }

    public function test_unicode_address_can_strip_ascii_structured_suffix(): void
    {
        $line1 = str_repeat('क', 150).', Karnataka - 562 112';
        $line2 = str_repeat('म', 40);

        [$out1, $out2] = ShiprocketAdhocAddressNormalizer::forPayload(
            $line1,
            $line2,
            'Karnataka',
            '562112',
        );

        $this->assertSame(str_repeat('क', 150), $out1);
        $this->assertSame($line2, $out2);
        $this->assertSame(190, ShiprocketAdhocAddressNormalizer::combinedCharacterLength($out1, $out2));
    }
}
