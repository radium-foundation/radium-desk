<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipCheckpointIdPolicy;
use InvalidArgumentException;

class RipCheckpointIdPolicyTest extends RipCheckpointTestCase
{
    public function test_composes_canonical_desk_checkpoint_id(): void
    {
        $policy = new RipCheckpointIdPolicy();
        $at = now(RipCheckpointIdPolicy::TIMEZONE)->setDate(2026, 10, 21)->setTime(12, 0);

        $composed = $policy->compose('pre-deploy-gate', '7441de0d65c1', $at);

        $this->assertSame('RIP-211026-1200-radium-desk-pre-deploy-gate-7441de0', $composed['checkpoint_id']);
    }

    public function test_rejects_invalid_change_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RipCheckpointIdPolicy())->compose('Bad Change', '7441de0d');
    }

    public function test_rejects_wrong_project_key_in_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RipCheckpointIdPolicy())->assertCheckpointId('RIP-211026-1200-rbox-test-7441de0');
    }
}
