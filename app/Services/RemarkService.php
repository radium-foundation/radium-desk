<?php

namespace App\Services;

use App\Data\RemarkMetadata;
use App\Models\Remark;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class RemarkService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly RemarkMentionParser $mentionParser,
    ) {}

    public function createForRemarkable(
        Model $remarkable,
        User $actor,
        string $body,
        ?Request $request = null,
    ): Remark {
        $normalizedBody = trim($body);
        $metadata = $this->buildInitialMetadata($normalizedBody);

        $remark = Remark::query()->create([
            'user_id' => $actor->id,
            'remarkable_type' => $remarkable->getMorphClass(),
            'remarkable_id' => $remarkable->getKey(),
            'body' => $normalizedBody,
            'metadata' => $metadata->toArray() !== [] ? $metadata->toArray() : null,
        ]);

        $this->syncMentions($remark, $remark->body);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'created',
            auditable: $remark,
            newValues: [
                'body' => $remark->body,
                'remarkable_type' => $remark->remarkable_type,
                'remarkable_id' => $remark->remarkable_id,
            ],
            request: $request,
        );

        if ($remarkable instanceof \App\Models\Incident) {
            $this->dashboardBroadcastService->serviceCaseRemarked($remarkable, $actor);
            app(\App\Services\Operations\TeamMemberActivityService::class)->recordCaseAction($actor);
        }

        return $remark;
    }

    private function syncMentions(Remark $remark, string $body): void
    {
        $mentionedUserIds = $this->mentionParser->mentionedUserIds($body);

        foreach ($mentionedUserIds as $userId) {
            $remark->mentions()->firstOrCreate([
                'user_id' => $userId,
            ]);
        }
    }

    private function buildInitialMetadata(string $body): RemarkMetadata
    {
        $aiMentions = $this->mentionParser->mentionedAiAgents($body);

        if ($aiMentions === []) {
            return new RemarkMetadata;
        }

        return (new RemarkMetadata)->withAiMentions($aiMentions);
    }
}
