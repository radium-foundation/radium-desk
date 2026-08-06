<?php

namespace App\Services\IraMemory;

use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\IraMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin list filters / search over ira_memories.
 */
class IraMemoryQueryService
{
    /**
     * @param  array{
     *     q?: string|null,
     *     memory_type?: string|null,
     *     source?: string|null,
     *     status?: string|null,
     *     pattern_kind?: string|null,
     *     decision_kind?: string|null,
     *     confidence_band?: string|null,
     *     created_from?: string|null,
     *     has_usage?: string|null,
     *     sort?: string|null,
     * }  $filters
     * @return LengthAwarePaginator<int, IraMemory>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = IraMemory::query()->with(['creator:id,name,first_name,last_name,email']);

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status === IraMemoryStatus::Deleted->value) {
            $query->onlyTrashed()->where('status', IraMemoryStatus::Deleted->value);
        } elseif ($status !== '') {
            $query->where('status', $status);
        } else {
            // Default corpus: everything still in the table (not soft-deleted).
            $query->where('status', '!=', IraMemoryStatus::Deleted->value);
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('pattern_value', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('decision_value', 'like', $like)
                    ->orWhere('uuid', 'like', $like)
                    ->orWhereHas('creator', function (Builder $creator) use ($like): void {
                        $creator->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    });
            });
        }

        $this->applyEnumFilter($query, 'memory_type', $filters['memory_type'] ?? null, IraMemoryType::class);
        $this->applyEnumFilter($query, 'source', $filters['source'] ?? null, IraMemorySource::class);
        $this->applyEnumFilter($query, 'pattern_kind', $filters['pattern_kind'] ?? null, IraMemoryPatternKind::class);
        $this->applyEnumFilter($query, 'decision_kind', $filters['decision_kind'] ?? null, IraMemoryDecisionKind::class);
        $this->applyEnumFilter($query, 'created_from', $filters['created_from'] ?? null, IraMemoryCreatedFrom::class);

        $band = trim((string) ($filters['confidence_band'] ?? ''));

        if ($band === 'high') {
            $query->where('confidence', '>=', 75);
        } elseif ($band === 'medium') {
            $query->whereBetween('confidence', [45, 74]);
        } elseif ($band === 'low') {
            $query->where('confidence', '<', 45);
        }

        $hasUsage = trim((string) ($filters['has_usage'] ?? ''));

        if ($hasUsage === 'yes') {
            $query->where('times_used', '>', 0);
        } elseif ($hasUsage === 'no') {
            $query->where('times_used', '=', 0);
        }

        $sort = trim((string) ($filters['sort'] ?? 'updated_at'));

        match ($sort) {
            'times_used' => $query->orderByDesc('times_used')->orderByDesc('id'),
            'last_used_at' => $query->orderByDesc('last_used_at')->orderByDesc('id'),
            'confidence' => $query->orderByDesc('confidence')->orderByDesc('id'),
            'pattern_value' => $query->orderBy('pattern_value')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function applyEnumFilter(Builder $query, string $column, mixed $raw, string $enumClass): void
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return;
        }

        if ($enumClass::tryFrom($value) === null) {
            return;
        }

        $query->where($column, $value);
    }
}
