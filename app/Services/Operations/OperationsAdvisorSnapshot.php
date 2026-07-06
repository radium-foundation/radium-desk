<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OperationsAdvisorSnapshot
{
    /** @var Collection<int, Incident>|null */
    private ?Collection $pendingAdminIncidents = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $activeIncidents = null;

    /** @var array{overdue_cases: int, warning_cases: int, service_overdue_cases: int, service_warning_cases: int, hardware_overdue_cases: int, hardware_warning_cases: int}|null */
    private ?array $slaCounts = null;

    /** @var Collection<int, Collection<int, Incident>>|null */
    private ?Collection $engineerWorkloads = null;

    /** @var Collection<int, IncidentWaitingState>|null */
    private ?Collection $longWaitingStates = null;

    /** @var Collection<string, Collection<int, Incident>>|null */
    private ?Collection $repeatComplaintCustomers = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $premiumCustomersWaiting = null;

    /** @var Collection<string, Collection<int, Incident>>|null */
    private ?Collection $amcEligibleCustomers = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $expiredWarrantyOpenCases = null;

    /** @var Collection<string, Collection<int, Incident>>|null */
    private ?Collection $repeatRepairCandidates = null;

    public function __construct(
        public readonly OperationsDashboardData $dashboard,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function pendingAdminIncidents(): Collection
    {
        if ($this->pendingAdminIncidents !== null) {
            return $this->pendingAdminIncidents;
        }

        return $this->pendingAdminIncidents = Incident::query()
            ->with(['order', 'assignee', 'activeWaitingState'])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            })
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function activeIncidents(): Collection
    {
        if ($this->activeIncidents !== null) {
            return $this->activeIncidents;
        }

        return $this->activeIncidents = Incident::query()
            ->with(['order', 'assignee'])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->get();
    }

    /**
     * @return array{
     *     overdue_cases: int,
     *     warning_cases: int,
     *     service_overdue_cases: int,
     *     service_warning_cases: int,
     *     hardware_overdue_cases: int,
     *     hardware_warning_cases: int
     * }
     */
    public function slaCounts(): array
    {
        if ($this->slaCounts !== null) {
            return $this->slaCounts;
        }

        $now = now();
        $pending = $this->pendingAdminIncidents();

        $pending = $this->pendingAdminIncidents();
        $servicePending = $pending->filter(
            fn (Incident $incident): bool => ! Order::isHardwareOrderId($incident->order?->order_id),
        );
        $hardwarePending = $pending->filter(
            fn (Incident $incident): bool => Order::isHardwareOrderId($incident->order?->order_id),
        );

        return $this->slaCounts = [
            'overdue_cases' => $this->countSlaStatus($pending, $now, ServiceCaseSlaStatus::Overdue),
            'warning_cases' => $this->countSlaStatus($pending, $now, ServiceCaseSlaStatus::Warning),
            'service_overdue_cases' => $this->countSlaStatus($servicePending, $now, ServiceCaseSlaStatus::Overdue),
            'service_warning_cases' => $this->countSlaStatus($servicePending, $now, ServiceCaseSlaStatus::Warning),
            'hardware_overdue_cases' => $this->countSlaStatus($hardwarePending, $now, ServiceCaseSlaStatus::Overdue),
            'hardware_warning_cases' => $this->countSlaStatus($hardwarePending, $now, ServiceCaseSlaStatus::Warning),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function countSlaStatus(Collection $incidents, \Illuminate\Support\Carbon $now, ServiceCaseSlaStatus $status): int
    {
        return $incidents
            ->filter(fn (Incident $incident): bool => $incident->slaStatus($now) === $status)
            ->count();
    }

    /**
     * @return Collection<int, Collection<int, Incident>>
     */
    public function engineerWorkloads(): Collection
    {
        if ($this->engineerWorkloads !== null) {
            return $this->engineerWorkloads;
        }

        return $this->engineerWorkloads = $this->activeIncidents()
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id !== null)
            ->groupBy('assigned_to_user_id');
    }

    /**
     * @return Collection<int, IncidentWaitingState>
     */
    public function longWaitingStates(int $minimumDays = 3): Collection
    {
        if ($this->longWaitingStates !== null) {
            return $this->longWaitingStates;
        }

        $threshold = now()->subDays($minimumDays);

        return $this->longWaitingStates = IncidentWaitingState::query()
            ->with(['incident.order', 'incident.assignee'])
            ->whereNull('cleared_at')
            ->where('started_at', '<=', $threshold)
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, Incident>>
     */
    public function repeatComplaintCustomers(): Collection
    {
        if ($this->repeatComplaintCustomers !== null) {
            return $this->repeatComplaintCustomers;
        }

        return $this->repeatComplaintCustomers = $this->activeIncidents()
            ->filter(fn (Incident $incident): bool => filled($incident->order?->customer_phone))
            ->groupBy(fn (Incident $incident): string => (string) $incident->order?->customer_phone)
            ->filter(fn (Collection $group): bool => $group->count() >= 2);
    }

    /**
     * @return Collection<int, Incident>
     */
    public function premiumCustomersWaiting(): Collection
    {
        if ($this->premiumCustomersWaiting !== null) {
            return $this->premiumCustomersWaiting;
        }

        $phonesWithMultipleOrders = Order::query()
            ->select('customer_phone')
            ->whereNotNull('customer_phone')
            ->groupBy('customer_phone')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('customer_phone');

        return $this->premiumCustomersWaiting = $this->pendingAdminIncidents()
            ->filter(fn (Incident $incident): bool => $phonesWithMultipleOrders->contains($incident->order?->customer_phone)
                && $incident->created_at !== null
                && $incident->created_at->lte(now()->subDays(2)));
    }

    /**
     * @return Collection<string, Collection<int, Incident>>
     */
    public function amcEligibleCustomers(): Collection
    {
        if ($this->amcEligibleCustomers !== null) {
            return $this->amcEligibleCustomers;
        }

        $pending = $this->pendingAdminIncidents();
        $phones = $pending
            ->map(fn (Incident $incident): ?string => $incident->order?->customer_phone)
            ->filter()
            ->unique()
            ->values();

        $orderCountsByPhone = $phones->isEmpty()
            ? collect()
            : Order::query()
                ->selectRaw('customer_phone, COUNT(*) as aggregate')
                ->whereIn('customer_phone', $phones)
                ->groupBy('customer_phone')
                ->pluck('aggregate', 'customer_phone');

        return $this->amcEligibleCustomers = $pending
            ->filter(function (Incident $incident) use ($orderCountsByPhone): bool {
                $phone = $incident->order?->customer_phone;

                if (! filled($phone)) {
                    return false;
                }

                if ((int) ($orderCountsByPhone[$phone] ?? 0) < 2) {
                    return false;
                }

                $metadata = $this->enrichmentSyncStore->metadata($incident->order_id) ?? [];
                $warranty = Str::lower((string) ($metadata['warranty'] ?? 'expired'));
                $amc = Str::lower((string) ($metadata['amc'] ?? 'not available'));

                return (Str::contains($warranty, 'expired') || $warranty === '')
                    && ! Str::contains($amc, 'active');
            })
            ->groupBy(fn (Incident $incident): string => (string) $incident->order?->customer_phone);
    }

    /**
     * @return Collection<int, Incident>
     */
    public function expiredWarrantyOpenCases(): Collection
    {
        if ($this->expiredWarrantyOpenCases !== null) {
            return $this->expiredWarrantyOpenCases;
        }

        return $this->expiredWarrantyOpenCases = $this->pendingAdminIncidents()
            ->filter(function (Incident $incident): bool {
                $metadata = $this->enrichmentSyncStore->metadata($incident->order_id) ?? [];
                $warranty = Str::lower((string) ($metadata['warranty'] ?? ''));

                return Str::contains($warranty, 'expired');
            })
            ->values();
    }

    /**
     * @return Collection<string, Collection<int, Incident>>
     */
    public function repeatRepairCandidates(): Collection
    {
        if ($this->repeatRepairCandidates !== null) {
            return $this->repeatRepairCandidates;
        }

        $phonesWithHistory = Incident::query()
            ->with('order')
            ->whereIn('status', [IncidentStatus::Closed, IncidentStatus::Resolved])
            ->get()
            ->groupBy(fn (Incident $incident): string => (string) $incident->order?->customer_phone)
            ->filter(fn (Collection $history): bool => $history->count() >= 2)
            ->keys();

        return $this->repeatRepairCandidates = $this->pendingAdminIncidents()
            ->filter(fn (Incident $incident): bool => $phonesWithHistory->contains($incident->order?->customer_phone))
            ->groupBy(fn (Incident $incident): string => (string) $incident->order?->customer_phone);
    }
}
