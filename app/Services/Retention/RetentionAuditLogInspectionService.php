<?php

namespace App\Services\Retention;

use App\Data\Retention\RetentionAuditLogInspectionSummary;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionAuditLogInspectionService
{
    /**
     * Read-only inspection for audit_logs retention planning.
     *
     * Never writes, updates, or deletes.
     */
    public function inspect(?Carbon $at = null): RetentionAuditLogInspectionSummary
    {
        $at ??= now();

        if (! Schema::hasTable('audit_logs')) {
            return $this->emptySummary($at);
        }

        $tableStats = $this->tableStatistics();
        $createdBounds = AuditLog::query()
            ->selectRaw('MIN(created_at) as min_created_at, MAX(created_at) as max_created_at')
            ->first();

        $countByEvent = AuditLog::query()
            ->selectRaw('event, COUNT(*) as aggregate_count')
            ->groupBy('event')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'event')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $topEventLimit = max(1, (int) config('retention.audit_logs.top_event_limit', 25));
        $topEventsByVolume = [];

        foreach (array_slice($countByEvent, 0, $topEventLimit, true) as $event => $count) {
            $topEventsByVolume[] = ['event' => (string) $event, 'count' => $count];
        }

        $countByCategory = $this->countByCategory();
        $mustKeepFamilyCounts = $this->mustKeepFamilyCounts();
        $mustKeepFamilyRowTotal = $this->mustKeepDistinctRowCount();
        $candidateCohorts = $this->candidateCohortSummaries($at);

        return new RetentionAuditLogInspectionSummary(
            inspectedAt: $at,
            tableTotalRows: AuditLog::query()->count(),
            tableDataBytes: $tableStats['data_bytes'],
            tableIndexBytes: $tableStats['index_bytes'],
            tableTotalBytes: $tableStats['total_bytes'],
            minCreatedAt: $this->formatDateTime($createdBounds?->min_created_at),
            maxCreatedAt: $this->formatDateTime($createdBounds?->max_created_at),
            rowsOlderThanDays: $this->rowsOlderThanDays($at),
            countByEvent: $countByEvent,
            topEventsByVolume: $topEventsByVolume,
            estimatedPayloadBytes: $this->estimatedPayloadBytes(),
            countByCategory: $countByCategory,
            mustKeepFamilyCounts: $mustKeepFamilyCounts,
            mustKeepFamilyRowTotal: $mustKeepFamilyRowTotal,
            candidateCohorts: $candidateCohorts,
            logicalSafety: $this->logicalSafetyNotes(),
            truncationIssue: config('retention.audit_logs.truncation_issue', []),
        );
    }

    /**
     * @return Builder<AuditLog>
     */
    public function incomingEmailNoiseCandidateQuery(Carbon $at): Builder
    {
        $days = (int) ($this->candidateCohortConfig('incoming_email_noise')['older_than_days'] ?? 90);
        $events = $this->candidateCohortConfig('incoming_email_noise')['events'] ?? [
            'incoming_email.received',
            'incoming_email.ignored',
        ];

        return AuditLog::query()
            ->where('created_at', '<', $at->copy()->subDays($days))
            ->whereIn('event', $events);
    }

    /**
     * @return Builder<AuditLog>
     */
    public function telemetryCandidateQuery(Carbon $at, int $days): Builder
    {
        $events = config('retention.audit_logs.telemetry_events', []);

        return AuditLog::query()
            ->where('created_at', '<', $at->copy()->subDays($days))
            ->whereIn('event', is_array($events) ? $events : []);
    }

    /**
     * @return Builder<AuditLog>
     */
    public function businessNonEmailCandidateQuery(Carbon $at): Builder
    {
        $days = (int) config('retention.business_audit_days', 365);

        return AuditLog::query()
            ->where('created_at', '<', $at->copy()->subDays($days))
            ->where('event', 'not like', 'incoming_email.%');
    }

    /**
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        $counts = [];
        $allEvents = AuditLog::query()->pluck('event');

        foreach ($allEvents as $event) {
            $category = $this->categorizeEvent(is_string($event) ? $event : '');
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function categorizeEvent(string $event): string
    {
        $categories = config('retention.audit_logs.categories', []);

        if (! is_array($categories)) {
            return 'other';
        }

        foreach ($categories as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if ($this->eventMatchesCategory($event, $definition)) {
                return (string) $key;
            }
        }

        return 'other';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function eventMatchesCategory(string $event, array $definition): bool
    {
        $patterns = $definition['patterns'] ?? [];
        $excludePatterns = $definition['exclude_patterns'] ?? [];
        $exactEvents = $definition['exact_events'] ?? [];

        if (is_array($exactEvents) && in_array($event, $exactEvents, true)) {
            return true;
        }

        if (is_array($excludePatterns)) {
            foreach ($excludePatterns as $pattern) {
                if (is_string($pattern) && $this->eventMatchesPattern($event, $pattern)) {
                    return false;
                }
            }
        }

        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $this->eventMatchesPattern($event, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function mustKeepFamilyCounts(): array
    {
        $counts = [];

        foreach ($this->mustKeepFamilies() as $key => $definition) {
            $counts[$key] = $this->countRowsMatchingFamily($definition);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function rowsOlderThanDays(Carbon $at): array
    {
        $cohorts = config('retention.audit_logs.age_cohort_days', []);
        $results = [];

        if (! is_array($cohorts)) {
            return $results;
        }

        foreach ($cohorts as $label => $days) {
            if (! is_string($label) || ! is_numeric($days)) {
                continue;
            }

            $results[$label] = AuditLog::query()
                ->where('created_at', '<', $at->copy()->subDays((int) $days))
                ->count();
        }

        return $results;
    }

    /**
     * @return list<array{key: string, label: string, classification: string, readers: list<string>, notes: string}>
     */
    public function logicalSafetyNotes(): array
    {
        $notes = config('retention.audit_logs.logical_safety', []);

        if (! is_array($notes)) {
            return [];
        }

        $normalized = [];

        foreach ($notes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized[] = [
                'key' => (string) ($entry['key'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'classification' => (string) ($entry['classification'] ?? ''),
                'readers' => array_values(array_filter(
                    is_array($entry['readers'] ?? null) ? $entry['readers'] : [],
                    static fn (mixed $reader): bool => is_string($reader) && $reader !== '',
                )),
                'notes' => (string) ($entry['notes'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $family
     */
    public function countRowsMatchingFamily(array $family): int
    {
        return $this->applyFamilyPatterns(AuditLog::query(), $family)->count();
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $family
     * @return Builder<AuditLog>
     */
    public function applyFamilyPatterns(Builder $query, array $family): Builder
    {
        $patterns = $family['patterns'] ?? [];
        $exactEvents = $family['exact_events'] ?? [];

        return $query->where(function (Builder $inner) use ($patterns, $exactEvents): void {
            $hasCondition = false;

            if (is_array($exactEvents) && $exactEvents !== []) {
                $inner->whereIn('event', array_values(array_filter(
                    $exactEvents,
                    static fn (mixed $event): bool => is_string($event) && $event !== '',
                )));
                $hasCondition = true;
            }

            if (! is_array($patterns)) {
                return;
            }

            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }

                if (str_ends_with($pattern, '%')) {
                    $hasCondition
                        ? $inner->orWhere('event', 'like', $pattern)
                        : $inner->where('event', 'like', $pattern);
                } else {
                    $hasCondition
                        ? $inner->orWhere('event', $pattern)
                        : $inner->where('event', $pattern);
                }

                $hasCondition = true;
            }
        });
    }

    public function eventMatchesPattern(string $event, string $pattern): bool
    {
        if (str_ends_with($pattern, '%')) {
            return str_starts_with($event, substr($pattern, 0, -1));
        }

        return $event === $pattern;
    }

    private function emptySummary(Carbon $at): RetentionAuditLogInspectionSummary
    {
        $cohortLabels = array_keys(config('retention.audit_logs.age_cohort_days', []));
        $emptyCohorts = array_fill_keys(
            is_array($cohortLabels) ? $cohortLabels : [],
            0,
        );

        $mustKeepFamilies = array_keys($this->mustKeepFamilies());

        return new RetentionAuditLogInspectionSummary(
            inspectedAt: $at,
            tableTotalRows: 0,
            tableDataBytes: null,
            tableIndexBytes: null,
            tableTotalBytes: null,
            minCreatedAt: null,
            maxCreatedAt: null,
            rowsOlderThanDays: $emptyCohorts,
            countByEvent: [],
            topEventsByVolume: [],
            estimatedPayloadBytes: 0,
            countByCategory: [],
            mustKeepFamilyCounts: array_fill_keys($mustKeepFamilies, 0),
            mustKeepFamilyRowTotal: 0,
            candidateCohorts: [],
            logicalSafety: $this->logicalSafetyNotes(),
            truncationIssue: config('retention.audit_logs.truncation_issue', []),
        );
    }

    /**
     * @return array{data_bytes: ?int, index_bytes: ?int, total_bytes: ?int}
     */
    private function tableStatistics(): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [
                'data_bytes' => null,
                'index_bytes' => null,
                'total_bytes' => null,
            ];
        }

        $row = DB::selectOne(
            'SELECT COALESCE(data_length, 0) AS data_bytes,
                    COALESCE(index_length, 0) AS index_bytes,
                    COALESCE(data_length, 0) + COALESCE(index_length, 0) AS total_bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?',
            ['audit_logs'],
        );

        if ($row === null) {
            return [
                'data_bytes' => null,
                'index_bytes' => null,
                'total_bytes' => null,
            ];
        }

        return [
            'data_bytes' => (int) ($row->data_bytes ?? 0),
            'index_bytes' => (int) ($row->index_bytes ?? 0),
            'total_bytes' => (int) ($row->total_bytes ?? 0),
        ];
    }

    private function estimatedPayloadBytes(): int
    {
        return (int) AuditLog::query()
            ->selectRaw('COALESCE(SUM('.$this->payloadLengthExpression().'), 0) as aggregate_bytes')
            ->value('aggregate_bytes');
    }

    private function payloadLengthExpression(): string
    {
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        return implode(' + ', [
            'COALESCE('.$length.'(COALESCE(CAST(old_values AS CHAR), "")), 0)',
            'COALESCE('.$length.'(COALESCE(CAST(new_values AS CHAR), "")), 0)',
            'COALESCE('.$length.'(COALESCE(user_agent, "")), 0)',
            'COALESCE('.$length.'(COALESCE(ip_address, "")), 0)',
            'COALESCE('.$length.'(COALESCE(event, "")), 0)',
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mustKeepFamilies(): array
    {
        $families = config('retention.audit_logs.must_keep_families', []);

        return is_array($families) ? $families : [];
    }

    private function mustKeepDistinctRowCount(): int
    {
        $families = $this->mustKeepFamilies();

        if ($families === []) {
            return 0;
        }

        return $this->applyMustKeepFamilies(AuditLog::query(), $families)->count();
    }

    /**
     * @param  array<string, array<string, mixed>>  $families
     */
    private function applyMustKeepFamilies(Builder $query, array $families): Builder
    {
        return $query->where(function (Builder $outer) use ($families): void {
            $first = true;

            foreach ($families as $family) {
                if (! is_array($family)) {
                    continue;
                }

                if ($first) {
                    $this->applyFamilyPatterns($outer, $family);
                    $first = false;

                    continue;
                }

                $outer->orWhere(function (Builder $inner) use ($family): void {
                    $this->applyFamilyPatterns($inner, $family);
                });
            }
        });
    }

    /**
     * @return array<string, array{label: string, count: int, older_than_days: int, estimated_payload_bytes: int, overlapping_must_keep_count: int}>
     */
    private function candidateCohortSummaries(Carbon $at): array
    {
        $summaries = [];
        $cohorts = config('retention.audit_logs.candidate_cohorts', []);

        if (! is_array($cohorts)) {
            return $summaries;
        }

        foreach ($cohorts as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                continue;
            }

            $query = match ($key) {
                'incoming_email_noise' => $this->incomingEmailNoiseCandidateQuery($at),
                'telemetry_90d' => $this->telemetryCandidateQuery($at, 90),
                'telemetry_180d' => $this->telemetryCandidateQuery($at, 180),
                'business_non_email' => $this->businessNonEmailCandidateQuery($at),
                default => null,
            };

            if ($query === null) {
                continue;
            }

            $days = match ($key) {
                'incoming_email_noise' => (int) ($definition['older_than_days'] ?? 90),
                'telemetry_90d' => 90,
                'telemetry_180d' => 180,
                'business_non_email' => (int) config('retention.business_audit_days', 365),
                default => 0,
            };

            $payloadBytes = (int) (clone $query)
                ->selectRaw('COALESCE(SUM('.$this->payloadLengthExpression().'), 0) as aggregate_bytes')
                ->value('aggregate_bytes');

            $summaries[$key] = [
                'label' => (string) ($definition['label'] ?? $key),
                'count' => (clone $query)->count(),
                'older_than_days' => $days,
                'estimated_payload_bytes' => $payloadBytes,
                'overlapping_must_keep_count' => $this->applyMustKeepFamilies(clone $query, $this->mustKeepFamilies())->count(),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateCohortConfig(string $key): array
    {
        $cohorts = config('retention.audit_logs.candidate_cohorts', []);

        if (! is_array($cohorts) || ! isset($cohorts[$key]) || ! is_array($cohorts[$key])) {
            return [];
        }

        return $cohorts[$key];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }
}
