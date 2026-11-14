<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipHostingerSnapshotPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class RipHostingerSnapshotPolicyTest extends TestCase
{
    public function test_valid_verified_block(): void
    {
        $policy = new RipHostingerSnapshotPolicy;
        $policy->validateRecoveryBlock([
            'schema_version' => 1,
            'provider' => 'hostinger',
            'virtual_machine_id' => 1910783,
            'hostname' => 'srv1910783.hstgr.cloud',
            'snapshot_id' => 455368,
            'snapshot_created_at' => '2026-11-14T03:40:37Z',
            'snapshot_expires_at' => '2026-11-15T03:40:37Z',
            'verified_at' => '2026-11-14T05:00:00Z',
            'verification_status' => RipHostingerSnapshotPolicy::STATUS_VERIFIED,
            'source_commit' => '968ad894',
        ]);
        $this->assertTrue(true);
    }

    public function test_rejects_wrong_vm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RipHostingerSnapshotPolicy)->validateRecoveryBlock([
            'schema_version' => 1,
            'provider' => 'hostinger',
            'virtual_machine_id' => 1,
            'hostname' => 'x',
            'verified_at' => 't',
            'verification_status' => 'verified',
        ]);
    }

    public function test_rejects_restorable_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RipHostingerSnapshotPolicy)->validateRecoveryBlock([
            'schema_version' => 1,
            'provider' => 'hostinger',
            'virtual_machine_id' => 1910783,
            'hostname' => 'srv1910783.hstgr.cloud',
            'verified_at' => 't',
            'verification_status' => 'restorable',
        ]);
    }

    public function test_rejects_authorization_reference_in_block(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorization_reference');
        (new RipHostingerSnapshotPolicy)->validateRecoveryBlock([
            'schema_version' => 1,
            'provider' => 'hostinger',
            'virtual_machine_id' => 1910783,
            'hostname' => 'srv1910783.hstgr.cloud',
            'snapshot_id' => 1,
            'verified_at' => '2026-11-14T05:00:00Z',
            'verification_status' => RipHostingerSnapshotPolicy::STATUS_VERIFIED,
            'authorization_reference' => 'RadiumDesk-P-14-18',
        ]);
    }
}