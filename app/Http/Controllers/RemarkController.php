<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRemarkRequest;
use App\Models\Remark;
use App\Services\AuditLogService;
use App\Services\RemarkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RemarkController extends Controller
{
    public function __construct(
        private readonly RemarkService $remarkService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function store(StoreRemarkRequest $request): RedirectResponse
    {
        $this->remarkService->createForRemarkable(
            remarkable: $request->resolveRemarkable(),
            actor: $request->user(),
            body: $request->string('body')->toString(),
            request: $request,
        );

        return redirect()
            ->back()
            ->with('status', 'remark-created')
            ->withFragment('activity-timeline');
    }

    public function destroy(Request $request, Remark $remark): RedirectResponse
    {
        $this->authorize('delete', $remark);

        $this->auditLogService->log(
            userId: $request->user()->id,
            event: 'deleted',
            auditable: $remark,
            oldValues: [
                'body' => $remark->body,
                'remarkable_type' => $remark->remarkable_type,
                'remarkable_id' => $remark->remarkable_id,
            ],
            request: $request,
        );

        $remark->delete();

        return redirect()
            ->back()
            ->with('status', 'remark-deleted')
            ->withFragment('activity-timeline');
    }
}
