<?php

namespace App\Services\AI;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class AIWorkbenchAuditService
{
    public const EVENT_SUGGESTION_VIEWED = 'ai_workbench.suggestion_viewed';

    public const EVENT_SUGGESTION_COPIED = 'ai_workbench.suggestion_copied';

    public const EVENT_SUGGESTION_INSERTED = 'ai_workbench.suggestion_inserted';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordViewed(
        Incident $incident,
        ?int $userId,
        string $artifactKey,
        ?Request $request = null,
        ?array $metadata = null,
    ): AuditLog {
        return $this->record(
            incident: $incident,
            userId: $userId,
            event: self::EVENT_SUGGESTION_VIEWED,
            artifactKey: $artifactKey,
            action: 'viewed',
            request: $request,
            metadata: $metadata,
        );
    }

    public function recordCopied(
        Incident $incident,
        ?int $userId,
        string $artifactKey,
        ?Request $request = null,
        ?array $metadata = null,
    ): AuditLog {
        return $this->record(
            incident: $incident,
            userId: $userId,
            event: self::EVENT_SUGGESTION_COPIED,
            artifactKey: $artifactKey,
            action: 'copied',
            request: $request,
            metadata: $metadata,
        );
    }

    public function recordInserted(
        Incident $incident,
        ?int $userId,
        string $artifactKey,
        string $target,
        ?Request $request = null,
        ?array $metadata = null,
    ): AuditLog {
        return $this->record(
            incident: $incident,
            userId: $userId,
            event: self::EVENT_SUGGESTION_INSERTED,
            artifactKey: $artifactKey,
            action: 'inserted',
            target: $target,
            request: $request,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function record(
        Incident $incident,
        ?int $userId,
        string $event,
        string $artifactKey,
        string $action,
        ?Request $request = null,
        ?string $target = null,
        ?array $metadata = null,
    ): AuditLog {
        return $this->auditLogService->log(
            userId: $userId,
            event: $event,
            auditable: $incident,
            newValues: array_filter([
                'artifact_key' => $artifactKey,
                'action' => $action,
                'target' => $target,
                'provider_name' => $metadata['provider_name'] ?? null,
                'confidence_score' => $metadata['confidence_score'] ?? null,
                'content_length' => $metadata['content_length'] ?? null,
                'content_hash' => $metadata['content_hash'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            request: $request,
        );
    }
}
