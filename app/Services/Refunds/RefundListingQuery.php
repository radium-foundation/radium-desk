<?php

namespace App\Services\Refunds;

use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared Refunds index query — used by RefundRequestController and
 * Dashboard Operations Workspace embed. No behaviour change vs legacy index.
 */
class RefundListingQuery
{
    /**
     * @return LengthAwarePaginator<int, RefundRequest>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $queue = $request->string('queue')->trim()->toString();

        return RefundRequest::query()
            ->with(['order', 'incident', 'requester'])
            ->when($queue !== '', function ($query) use ($queue) {
                match ($queue) {
                    'requested', 'pending_approval' => $query->where('status', RefundStatus::Pending->value),
                    'pending_execution' => $query->where('status', RefundStatus::PendingExecution->value),
                    'completed_today' => $query->whereIn('status', [
                        RefundStatus::Completed->value,
                        RefundStatus::Closed->value,
                    ])->whereDate('executed_at', now()->toDateString()),
                    'rejected' => $query->where('status', RefundStatus::Rejected->value),
                    default => null,
                };
            })
            ->when($request->filled('reference_no'), function ($query) use ($request) {
                $query->where(
                    'reference_no',
                    'like',
                    '%'.$request->string('reference_no')->trim().'%',
                );
            })
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->whereHas('order', function ($orderQuery) use ($request) {
                    $orderQuery->where(
                        'order_id',
                        'like',
                        '%'.$request->string('order_id')->trim().'%',
                    );
                });
            })
            ->when($request->filled('incident_reference_no'), function ($query) use ($request) {
                $query->whereHas('incident', function ($incidentQuery) use ($request) {
                    $incidentQuery->where(
                        'reference_no',
                        'like',
                        '%'.$request->string('incident_reference_no')->trim().'%',
                    );
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->trim());
            })
            ->when($request->filled('requested_by'), function ($query) use ($request) {
                $query->where('requested_by', $request->integer('requested_by'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->string('date_from')->trim());
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->string('date_to')->trim());
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @return Collection<int, User>
     */
    public function requesters(): Collection
    {
        return User::query()
            ->whereIn('id', RefundRequest::query()->distinct()->pluck('requested_by'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array{pending_approval: int, pending_execution: int, completed_today: int, rejected: int}
     */
    public function queueCounts(): array
    {
        return [
            'pending_approval' => RefundRequest::query()->where('status', RefundStatus::Pending)->count(),
            'pending_execution' => RefundRequest::query()->where('status', RefundStatus::PendingExecution)->count(),
            'completed_today' => RefundRequest::query()
                ->whereIn('status', [RefundStatus::Completed, RefundStatus::Closed])
                ->whereDate('executed_at', now()->toDateString())
                ->count(),
            'rejected' => RefundRequest::query()->where('status', RefundStatus::Rejected)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFrom(Request $request): array
    {
        return $request->only([
            'reference_no',
            'order_id',
            'incident_reference_no',
            'status',
            'requested_by',
            'date_from',
            'date_to',
            'queue',
        ]);
    }
}
