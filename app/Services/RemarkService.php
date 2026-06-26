<?php

namespace App\Services;

use App\Models\Remark;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class RemarkService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function createForRemarkable(
        Model $remarkable,
        User $actor,
        string $body,
        ?Request $request = null,
    ): Remark {
        $remark = Remark::query()->create([
            'user_id' => $actor->id,
            'remarkable_type' => $remarkable->getMorphClass(),
            'remarkable_id' => $remarkable->getKey(),
            'body' => trim($body),
        ]);

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
        }

        return $remark;
    }
}
