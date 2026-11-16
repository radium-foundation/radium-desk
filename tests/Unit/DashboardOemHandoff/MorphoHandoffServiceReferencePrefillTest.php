<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Models\DashboardOemHandoffSession;
use App\Services\DashboardOemHandoff\MorphoHandoffServiceReferencePrefill;
use App\Support\Oem\MorphoWhitelistQualification;
use Tests\TestCase;

class MorphoHandoffServiceReferencePrefillTest extends TestCase
{
    private MorphoHandoffServiceReferencePrefill $prefill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefill = new MorphoHandoffServiceReferencePrefill();
    }

    public function test_builds_eligible_prefill_for_already_whitelisted_morpho_draft(): void
    {
        $session = new DashboardOemHandoffSession([
            'public_id' => 'sess-1',
            'incident_id' => 42,
            'serial_number' => '2441I041803',
            'device_model' => 'MFS 110',
        ]);

        $draft = [
            'outcome' => 'found',
            'whitelist_qualification' => MorphoWhitelistQualification::ALREADY_WHITELISTED,
            'serial_number' => '2441I041803',
            'fields' => [
                'whitelist_status' => 'Device already whitelisted',
                'whitelist_date' => '2024-05-01',
                'expiry_date' => '2027-05-01',
                'device_type' => 'MFS110',
            ],
        ];

        $result = $this->prefill->build($session, $draft);

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['suggested_transaction_id']);
        $this->assertSame('2024-05-01', $result['oem_review']['whitelist_date']);
    }

    public function test_rejects_device_type_mismatch(): void
    {
        $session = new DashboardOemHandoffSession([
            'public_id' => 'sess-2',
            'incident_id' => 43,
            'serial_number' => '2441I041803',
            'device_model' => 'MFS 110',
        ]);

        $draft = [
            'outcome' => 'found',
            'whitelist_qualification' => MorphoWhitelistQualification::ALREADY_WHITELISTED,
            'serial_number' => '2441I041803',
            'fields' => [
                'whitelist_status' => 'Whitelisted',
                'device_type' => 'WRONGMODEL',
            ],
        ];

        $result = $this->prefill->build($session, $draft);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('device type', strtolower((string) $result['rejection_reason']));
    }

    public function test_rejects_valid_not_whitelisted(): void
    {
        $session = new DashboardOemHandoffSession([
            'public_id' => 'sess-3',
            'incident_id' => 44,
            'serial_number' => '2441I041803',
            'device_model' => 'MFS 110',
        ]);

        $draft = [
            'outcome' => 'found',
            'whitelist_qualification' => MorphoWhitelistQualification::VALID_NOT_WHITELISTED,
            'serial_number' => '2441I041803',
            'fields' => ['device_validity' => 'Valid'],
        ];

        $result = $this->prefill->build($session, $draft);

        $this->assertFalse($result['eligible']);
    }
}