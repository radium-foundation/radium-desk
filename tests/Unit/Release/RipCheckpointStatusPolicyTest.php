<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipCheckpointStatusPolicy;
use InvalidArgumentException;

class RipCheckpointStatusPolicyTest extends RipCheckpointTestCase
{
    public function test_smoke_verified_requires_evidence(): void
    {
        $policy = new RipCheckpointStatusPolicy();

        $this->expectException(InvalidArgumentException::class);
        $policy->assertStatusTransitionRequirements(RipCheckpointStatusPolicy::SMOKE_VERIFIED, []);
    }

    public function test_known_good_requires_owner_signoff(): void
    {
        $policy = new RipCheckpointStatusPolicy();

        $this->expectException(InvalidArgumentException::class);
        $policy->assertStatusTransitionRequirements(RipCheckpointStatusPolicy::KNOWN_GOOD, [
            'smoke_evidence' => ['verified' => true],
        ]);
    }
}
