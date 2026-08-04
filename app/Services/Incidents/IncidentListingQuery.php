<?php

namespace App\Services\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared Active/Service Cases index query — used by IncidentController and
 * Dashboard Operations Workspace embed. No behaviour change vs legacy index.
 */
class IncidentListingQuery
{
    /**
     * @return LengthAwarePaginator<int, Incident>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return Incident::query()
            ->with(['order', 'creator'])
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->whereHas('order', function ($orderQuery) use ($request) {
                    $orderQuery->where('order_id', 'like', '%'.$request->string('order_id')->trim().'%');
                });
            })
            ->when($request->filled('reference_no'), function ($query) use ($request) {
                $query->matchingReference($request->string('reference_no')->trim()->toString());
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->where('category', $request->string('category')->trim());
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = $request->string('status')->trim()->toString();

                if ($status === 'active') {
                    $query->whereIn('status', IncidentStatus::operationallyActive());

                    return;
                }

                $query->where('status', $status);
            })
            ->when($request->filled('source'), function ($query) use ($request) {
                $query->where('source', $request->string('source')->trim());
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
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return Incident::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFrom(Request $request): array
    {
        return $request->only([
            'order_id',
            'reference_no',
            'category',
            'status',
            'source',
            'date_from',
            'date_to',
        ]);
    }
}
