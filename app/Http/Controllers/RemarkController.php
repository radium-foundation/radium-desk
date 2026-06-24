<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRemarkRequest;
use App\Models\Remark;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RemarkController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function store(StoreRemarkRequest $request): RedirectResponse
    {
        $remarkable = $request->resolveRemarkable();

        $remark = Remark::query()->create([
            'user_id' => $request->user()->id,
            'remarkable_type' => $remarkable->getMorphClass(),
            'remarkable_id' => $remarkable->getKey(),
            'body' => $request->string('body')->trim()->toString(),
        ]);

        $this->auditLogService->log(
            userId: $request->user()->id,
            event: 'created',
            auditable: $remark,
            newValues: [
                'body' => $remark->body,
                'remarkable_type' => $remark->remarkable_type,
                'remarkable_id' => $remark->remarkable_id,
            ],
            request: $request,
        );

        return redirect()
            ->back()
            ->with('status', 'remark-created')
            ->withFragment('remarks-timeline');
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
            ->withFragment('remarks-timeline');
    }
}
