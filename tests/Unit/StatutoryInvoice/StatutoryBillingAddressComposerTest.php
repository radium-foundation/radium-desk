<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Support\StatutoryInvoice\StatutoryBillingAddressComposer;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryBillingAddressComposerTest extends TestCase
{
    private StatutoryBillingAddressComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statutory_invoices.billing_address_max_length' => 65535]);
        $this->composer = app(StatutoryBillingAddressComposer::class);
    }

    public function test_flat_only_address_at_255_and_256_characters_is_persistable(): void
    {
        foreach ([255, 256] as $length) {
            $flat = str_repeat('A', $length);
            $composed = $this->composer->compose($flat, null);

            $this->assertSame($flat, $composed['flat']);
            $this->assertNull($composed['structured']);
            $this->assertSame($length, strlen((string) $composed['flat']));
        }
    }

    public function test_rbp1697_shaped_structured_drops_redundant_line2_and_shortens_flat(): void
    {
        $structured = $this->rbp1697Structured();
        $rawFlat = StatutoryBillingStructured::flattenForPersistence($structured);
        $rawFlatWithDuplicateLine = $structured['line1'].', '.$structured['line2'].', '
            .$structured['city'].', '.$structured['state'].' - '.$structured['pincode'];

        $this->assertGreaterThan(255, strlen($rawFlatWithDuplicateLine));

        $composed = $this->composer->compose($rawFlatWithDuplicateLine, $structured);

        $this->assertArrayNotHasKey('line2', $composed['structured'] ?? []);
        $this->assertLessThan(255, strlen((string) $composed['flat']));
        $this->assertSame($rawFlat, $composed['flat']);
    }

    public function test_distinct_line1_and_line2_are_preserved(): void
    {
        $structured = [
            'line1' => '12 Industrial Estate',
            'line2' => 'Near Metro Pillar 42',
            'city' => 'Noida',
            'state' => 'Uttar Pradesh',
            'pincode' => '201301',
        ];

        $composed = $this->composer->composeFromRaw($structured);

        $this->assertSame('Near Metro Pillar 42', $composed['structured']['line2'] ?? null);
        $this->assertStringContainsString('Near Metro Pillar 42', (string) $composed['flat']);
    }

    public function test_compose_from_raw_string_is_identity_when_under_limit(): void
    {
        $flat = '1 Test Street, Delhi, 110001';

        $composed = $this->composer->composeFromRaw($flat);

        $this->assertSame($flat, $composed['flat']);
        $this->assertNull($composed['structured']);
    }

    public function test_assert_persistable_throws_validation_exception_when_over_configured_max(): void
    {
        config(['statutory_invoices.billing_address_max_length' => 100]);
        $flat = str_repeat('B', 101);

        try {
            $this->composer->compose($flat, null);
            $this->fail('Expected billing address length validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('billing_address', $exception->errors());
        }
    }

    /**
     * @return array<string, string>
     */
    private function rbp1697Structured(): array
    {
        return [
            'line1' => 'Adarsha nagar, opposite ayyappa swamy temple, village : CHENNUR, MANDAL : CHENNUR, district : MANCHERIAL, srate: telangana - 504201',
            'line2' => 'Opposite ayyappa swamy temple, village CHENNUR, mandal CHENNUR, DISTRICT MANCHERIAL, STATE TELANGANA - 504201',
            'city' => 'Kairmnagar',
            'state' => 'Telangana',
            'pincode' => '504201',
        ];
    }
}
