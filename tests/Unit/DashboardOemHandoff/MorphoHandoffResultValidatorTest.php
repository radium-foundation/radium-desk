<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Services\DashboardOemHandoff\MorphoHandoffResultValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MorphoHandoffResultValidatorTest extends TestCase
{
  private MorphoHandoffResultValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MorphoHandoffResultValidator();
    }

    public function test_accepts_matching_morpho_fields(): void
    {
        $result = $this->validator->validateDraft('2441I041803', [
            'outcome' => 'found',
            'fields' => [
                'serial_number' => '2441I041803',
                'device_validity' => 'Valid',
                'whitelist_status' => 'Whitelisted',
                'expiry_date' => '15/01/2027',
            ],
        ]);

        $this->assertSame('morpho', $result['oem']);
        $this->assertSame('2027-01-15', $result['fields']['expiry_date']);
        $this->assertSame('already_whitelisted', $result['whitelist_qualification']);
        $this->assertSame(2, $result['schema_version']);
    }

    public function test_marks_valid_without_whitelist_as_valid_not_whitelisted(): void
    {
        $result = $this->validator->validateDraft('2441I041803', [
            'outcome' => 'found',
            'fields' => [
                'serial_number' => '2441I041803',
                'device_validity' => 'Valid',
            ],
        ]);

        $this->assertSame('valid_not_whitelisted', $result['whitelist_qualification']);
    }

    public function test_rejects_serial_mismatch(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateDraft('2441I041803', [
            'outcome' => 'found',
            'fields' => [
                'serial_number' => 'OTHER0001',
                'device_validity' => 'Valid',
            ],
        ]);
    }
}