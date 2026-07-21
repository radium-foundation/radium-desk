<?php

namespace App\Data\Assignment;

readonly class AssignmentOriginRepairRow
{
    public function __construct(
        public int $incidentId,
        public string $serviceCase,
        public ?string $orderId,
        public string $assigneeName,
        public string $oldOrigin,
        public string $newOrigin,
        public string $matchingAuditEvent,
        public int $matchingAuditLogId,
    ) {}
}
