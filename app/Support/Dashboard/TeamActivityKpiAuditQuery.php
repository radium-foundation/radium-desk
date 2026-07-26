<?php

namespace App\Support\Dashboard;

use App\Enums\RemarkOrigin;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\Remark;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamActivityKpiAuditQuery
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function todayCountsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $allowlist = $this->humanCountAllowlist();

        if ($allowlist === []) {
            return [];
        }

        $dayStart ??= $this->dayStart();
        $filteredEvents = ['created', 'deleted', 'whatsapp.template_sent'];
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, $filteredEvents, true),
        ));

        $counts = array_fill_keys($userIds, 0);

        if ($directEvents !== []) {
            foreach (
                AuditLog::query()
                    ->selectRaw('user_id, COUNT(*) as aggregate_count')
                    ->whereIn('user_id', $userIds)
                    ->whereIn('event', $directEvents)
                    ->where('created_at', '>=', $dayStart)
                    ->groupBy('user_id')
                    ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
            ) {
                $counts[(int) $userId] += (int) $aggregate;
            }
        }

        if (in_array('whatsapp.template_sent', $allowlist, true)) {
            $this->mergeCounts($counts, $this->manualWhatsAppCounts($userIds, $dayStart));
        }

        if (in_array('created', $allowlist, true)) {
            $this->mergeCounts($counts, $this->manualRemarkCreatedCounts($userIds, $dayStart));
        }

        if (in_array('deleted', $allowlist, true)) {
            $this->mergeCounts($counts, $this->manualRemarkDeletedCounts($userIds, $dayStart));
        }

        return $counts;
    }

    public function todayCountForIra(?Carbon $dayStart = null): int
    {
        $countEvents = $this->iraCountAllowlist();

        if ($countEvents === []) {
            return 0;
        }

        $dayStart ??= $this->dayStart();

        return (int) AuditLog::query()
            ->whereIn('event', $countEvents)
            ->where('created_at', '>=', $dayStart)
            ->distinct()
            ->count('auditable_id');
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<AuditLog>>
     */
    public function todayCountedAuditsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $dayStart ??= $this->dayStart();
        $buckets = array_fill_keys($userIds, []);

        foreach ($this->todayCountedAuditLogsForUsers($userIds, $dayStart) as $log) {
            $userId = (int) $log->user_id;
            $buckets[$userId][] = $log;
        }

        return $buckets;
    }

    /**
     * @return list<AuditLog>
     */
    public function todayCountedAuditsForIra(?Carbon $dayStart = null): array
    {
        $countEvents = $this->iraCountAllowlist();

        if ($countEvents === []) {
            return [];
        }

        $dayStart ??= $this->dayStart();

        $latestIds = AuditLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('event', $countEvents)
            ->where('created_at', '>=', $dayStart)
            ->groupBy('auditable_type', 'auditable_id')
            ->pluck('id')
            ->filter()
            ->all();

        if ($latestIds === []) {
            return [];
        }

        return AuditLog::query()
            ->whereIn('id', $latestIds)
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function todayCountedAuditLogsForUsers(array $userIds, Carbon $dayStart): Collection
    {
        $allowlist = $this->humanCountAllowlist();

        if ($allowlist === []) {
            return collect();
        }

        $filteredEvents = ['created', 'deleted', 'whatsapp.template_sent'];
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, $filteredEvents, true),
        ));

        $logs = collect();

        if ($directEvents !== []) {
            $logs = $logs->merge(
                AuditLog::query()
                    ->whereIn('user_id', $userIds)
                    ->whereIn('event', $directEvents)
                    ->where('created_at', '>=', $dayStart)
                    ->get(),
            );
        }

        if (in_array('whatsapp.template_sent', $allowlist, true)) {
            $logs = $logs->merge($this->manualWhatsAppAudits($userIds, $dayStart));
        }

        if (in_array('created', $allowlist, true)) {
            $logs = $logs->merge($this->manualRemarkCreatedAudits($userIds, $dayStart));
        }

        if (in_array('deleted', $allowlist, true)) {
            $logs = $logs->merge($this->manualRemarkDeletedAudits($userIds, $dayStart));
        }

        return $logs
            ->sortByDesc(static fn (AuditLog $log): array => [
                $log->created_at?->getTimestamp() ?? 0,
                (int) $log->id,
            ])
            ->values();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualWhatsAppCounts(array $userIds, Carbon $dayStart): array
    {
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate_count')
                ->whereIn('user_id', $userIds)
                ->where('event', 'whatsapp.template_sent')
                ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
                ->where('created_at', '>=', $dayStart)
                ->groupBy('user_id')
                ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualWhatsAppAudits(array $userIds, Carbon $dayStart): Collection
    {
        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'whatsapp.template_sent')
            ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
            ->where('created_at', '>=', $dayStart)
            ->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualRemarkCreatedCounts(array $userIds, Carbon $dayStart): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate_count')
                ->whereIn('user_id', $userIds)
                ->where('event', 'created')
                ->where('auditable_type', $remarkMorph)
                ->where('created_at', '>=', $dayStart)
                ->where('new_values->origin', RemarkOrigin::Manual->value)
                ->groupBy('user_id')
                ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualRemarkCreatedAudits(array $userIds, Carbon $dayStart): Collection
    {
        $remarkMorph = (new Remark)->getMorphClass();

        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'created')
            ->where('auditable_type', $remarkMorph)
            ->where('created_at', '>=', $dayStart)
            ->where('new_values->origin', RemarkOrigin::Manual->value)
            ->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualRemarkDeletedCounts(array $userIds, Carbon $dayStart): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate_count')
                ->whereIn('user_id', $userIds)
                ->where('event', 'deleted')
                ->where('auditable_type', $remarkMorph)
                ->where('created_at', '>=', $dayStart)
                ->where('old_values->origin', RemarkOrigin::Manual->value)
                ->groupBy('user_id')
                ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualRemarkDeletedAudits(array $userIds, Carbon $dayStart): Collection
    {
        $remarkMorph = (new Remark)->getMorphClass();

        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'deleted')
            ->where('auditable_type', $remarkMorph)
            ->where('created_at', '>=', $dayStart)
            ->where('old_values->origin', RemarkOrigin::Manual->value)
            ->get();
    }

    /**
     * @param  array<int, int>  $target
     * @param  array<int, int>  $additions
     */
    private function mergeCounts(array &$target, array $additions): void
    {
        foreach ($additions as $userId => $count) {
            $target[$userId] = ($target[$userId] ?? 0) + $count;
        }
    }

    private function dayStart(): Carbon
    {
        return Carbon::now()->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function humanCountAllowlist(): array
    {
        $events = config('dashboard-team-activity.event_count_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function iraCountAllowlist(): array
    {
        $events = config('dashboard-team-activity.ira_event_count_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }
}
