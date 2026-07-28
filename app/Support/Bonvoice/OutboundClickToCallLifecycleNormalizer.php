<?php

namespace App\Support\Bonvoice;

use App\Enums\OutboundClickToCallLifecycleStatus;
use App\Support\BonvoiceCallStatuses;

/**
 * Maps BonVoice provider status strings to normalized outbound click-to-call lifecycle states.
 *
 * Frontend must consume only {@see OutboundClickToCallLifecycleStatus} values — never raw provider status.
 */
final class OutboundClickToCallLifecycleNormalizer
{
    public static function normalize(
        ?string $status,
        ?string $agentStatus = null,
        ?string $leg = null,
    ): ?OutboundClickToCallLifecycleStatus {
        $statusNorm = BonvoiceCallStatuses::normalize($status);
        $agentStatusNorm = BonvoiceCallStatuses::normalize($agentStatus);
        $legNorm = strtoupper(trim((string) $leg));

        if ($statusNorm === null) {
            return null;
        }

        if ($statusNorm === 'BUSY') {
            return OutboundClickToCallLifecycleStatus::Busy;
        }

        if (in_array($statusNorm, ['NOANSWER', 'NOINPUT'], true)) {
            return OutboundClickToCallLifecycleStatus::NoAnswer;
        }

        if ($statusNorm === 'FAILED') {
            return OutboundClickToCallLifecycleStatus::Failed;
        }

        if (in_array($statusNorm, ['CANCELLED', 'CANCELED'], true)) {
            return OutboundClickToCallLifecycleStatus::Cancelled;
        }

        if ($statusNorm === 'COMPLETED') {
            return OutboundClickToCallLifecycleStatus::Completed;
        }

        // Ringing must win over AgentStatus (e.g. ON CALL) — Connected requires Status=ANSWERED.
        if (in_array($statusNorm, BonvoiceCallStatuses::RINGING, true)) {
            return $legNorm === 'B'
                ? OutboundClickToCallLifecycleStatus::Ringing
                : OutboundClickToCallLifecycleStatus::Calling;
        }

        if ($statusNorm === 'ANSWERED' && $agentStatusNorm === 'AVAILABLE') {
            return OutboundClickToCallLifecycleStatus::Completed;
        }

        if ($statusNorm === 'ANSWERED') {
            return OutboundClickToCallLifecycleStatus::Answered;
        }

        if (in_array($statusNorm, ['DIALING', 'CALLING', 'PROGRESS', 'IDLE'], true)) {
            return OutboundClickToCallLifecycleStatus::Calling;
        }

        return null;
    }
}
