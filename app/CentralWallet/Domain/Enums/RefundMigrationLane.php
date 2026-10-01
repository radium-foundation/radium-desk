<?php

namespace App\CentralWallet\Domain\Enums;

enum RefundMigrationLane: string
{
    case Lane1SpokeCutover = 'lane_1_spoke_cutover';
    case Lane2AmbiguityResolution = 'lane_2_ambiguity_resolution';
    case Lane3RefundProvenance = 'lane_3_refund_provenance';
    case Lane3BlockedInsufficientEvidence = 'lane_3_blocked_insufficient_evidence';

    public static function fromManifestLane(string $manifestLane): self
    {
        return match ($manifestLane) {
            'LANE_1_SPOKE_CUTOVER',
            'LANE_1_SPOKE_CUTOVER_COLLISION_OVERRIDE_RD_ORDER' => self::Lane1SpokeCutover,
            'LANE_2_AMBIGUITY_RESOLUTION' => self::Lane2AmbiguityResolution,
            'LANE_3_REFUND_PROVENANCE' => self::Lane3RefundProvenance,
            'LANE_3_BLOCKED_INSUFFICIENT_EVIDENCE' => self::Lane3BlockedInsufficientEvidence,
            default => throw new \InvalidArgumentException('unknown_manifest_lane:'.$manifestLane),
        };
    }

    public function requiresSpokeSource(): bool
    {
        return $this === self::Lane1SpokeCutover || $this === self::Lane2AmbiguityResolution;
    }

    public function requiresOwnerResolution(): bool
    {
        return $this === self::Lane2AmbiguityResolution
            || $this === self::Lane3BlockedInsufficientEvidence;
    }
}
