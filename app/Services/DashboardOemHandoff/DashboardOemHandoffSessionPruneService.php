<?php

namespace App\Services\DashboardOemHandoff;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Models\DashboardOemHandoffSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class DashboardOemHandoffSessionPruneService
{
    public function countEligible(?Carbon $now = null): int
    {
        return (int) $this->eligibleQuery($now)->count();
    }

    /**
     * @return list<int>
     */
    public function prune(bool $dryRun = true, ?int $limit = null, ?Carbon $now = null): array
    {
        $query = $this->eligibleQuery($now)->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $ids = $query->pluck('id')->all();
        if ($dryRun || $ids === []) {
            return $ids;
        }

        DashboardOemHandoffSession::query()->whereIn('id', $ids)->delete();

        return $ids;
    }

    /**
     * @return Builder<DashboardOemHandoffSession>
     */
    private function eligibleQuery(?Carbon $now = null)
    {
        $now ??= now();
        $graceHours = max(1, (int) config('dashboard_oem_handoff.prune_grace_hours_after_expiry', 24));
        $cutoff = $now->copy()->subHours($graceHours);

        return DashboardOemHandoffSession::query()
            ->where(function ($outer) use ($cutoff) {
                $outer
                    ->where(function ($q) use ($cutoff) {
                        $q->whereIn('status', [
                            DashboardOemHandoffSessionStatus::Cancelled->value,
                            DashboardOemHandoffSessionStatus::Expired->value,
                        ])->where('expires_at', '<', $cutoff);
                    })
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->where('status', DashboardOemHandoffSessionStatus::Reviewed->value)
                            ->whereNotNull('draft_reviewed_at')
                            ->where('draft_reviewed_at', '<', $cutoff);
                    })
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereIn('status', [
                            DashboardOemHandoffSessionStatus::OpeningPortal->value,
                            DashboardOemHandoffSessionStatus::AwaitingVerification->value,
                        ])
                            ->whereNull('draft_submitted_at')
                            ->where('expires_at', '<', $cutoff);
                    })
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->where('status', DashboardOemHandoffSessionStatus::DraftReady->value)
                            ->where('expires_at', '<', $cutoff);
                    });
            })
            ->where(function ($q) use ($now) {
                $q->where('expires_at', '<', $now)
                    ->orWhere('status', DashboardOemHandoffSessionStatus::Reviewed->value);
            });
    }
}
