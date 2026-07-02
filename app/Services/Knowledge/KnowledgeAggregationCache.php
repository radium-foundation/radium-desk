<?php

namespace App\Services\Knowledge;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Support\Collection;

class KnowledgeAggregationCache
{
    private ?Collection $closedIncidents = null;

    private ?float $averageRepairTurnaroundDays = null;

    /** @var array{detected: bool, summary: string|null}|null */
    private ?array $repeatIssue = null;

    private ?float $historicalSuccessRate = null;

    private ?float $repeatFailurePercentage = null;

    private ?int $similarIncidentCount = null;

    private ?string $mostCommonResolution = null;

    /** @var list<string>|null */
    private ?array $topRecommendedFixes = null;

    public function __construct(
        private readonly Collection $incidents,
        private readonly Incident $currentIncident,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function closedIncidents(): Collection
    {
        if ($this->closedIncidents !== null) {
            return $this->closedIncidents;
        }

        return $this->closedIncidents = $this->incidents->filter(
            fn (Incident $incident) => $incident->id !== $this->currentIncident->id
                && in_array($incident->status, [IncidentStatus::Closed, IncidentStatus::Resolved], true),
        );
    }

    public function averageRepairTurnaroundDays(): ?float
    {
        if ($this->averageRepairTurnaroundDays !== null) {
            return $this->averageRepairTurnaroundDays;
        }

        $closed = $this->closedIncidents()->filter(
            fn (Incident $incident) => $incident->created_at !== null && $incident->updated_at !== null,
        );

        if ($closed->isEmpty()) {
            return $this->averageRepairTurnaroundDays = null;
        }

        $totalDays = $closed->sum(
            fn (Incident $incident): float => (float) $incident->created_at->diffInDays($incident->updated_at),
        );

        return $this->averageRepairTurnaroundDays = round($totalDays / $closed->count(), 1);
    }

    /**
     * @return array{detected: bool, summary: string|null}
     */
    public function repeatIssue(): array
    {
        if ($this->repeatIssue !== null) {
            return $this->repeatIssue;
        }

        $closed = $this->closedIncidents();
        $current = $this->currentIncident;

        $sameTitle = $closed->filter(
            fn (Incident $incident) => strtolower(trim($incident->title)) === strtolower(trim($current->title)),
        );

        if ($sameTitle->isNotEmpty()) {
            return $this->repeatIssue = [
                'detected' => true,
                'summary' => 'Repeat issue "'.$current->title.'" seen on '.$sameTitle->count().' prior case(s).',
            ];
        }

        $sameOrder = $closed->where('order_id', $current->order_id);

        if ($sameOrder->count() >= 1) {
            return $this->repeatIssue = [
                'detected' => true,
                'summary' => 'Prior repair history on this order ('.$sameOrder->count().' closed case(s)).',
            ];
        }

        return $this->repeatIssue = ['detected' => false, 'summary' => null];
    }

    public function historicalSuccessRate(): float
    {
        if ($this->historicalSuccessRate !== null) {
            return $this->historicalSuccessRate;
        }

        $total = $this->incidents->count();

        if ($total === 0) {
            return $this->historicalSuccessRate = 0.0;
        }

        $closed = $this->closedIncidents()->count();

        return $this->historicalSuccessRate = round(($closed / $total) * 100, 1);
    }

    public function repeatFailurePercentage(): float
    {
        if ($this->repeatFailurePercentage !== null) {
            return $this->repeatFailurePercentage;
        }

        $repeat = $this->repeatIssue();

        if (! $repeat['detected']) {
            return $this->repeatFailurePercentage = 0.0;
        }

        $closed = max(1, $this->closedIncidents()->count());

        return $this->repeatFailurePercentage = round(min(100, (1 / $closed) * 100), 1);
    }

    public function similarIncidentCount(): int
    {
        if ($this->similarIncidentCount !== null) {
            return $this->similarIncidentCount;
        }

        $current = $this->currentIncident;

        return $this->similarIncidentCount = $this->incidents
            ->filter(fn (Incident $incident) => $incident->id !== $current->id
                && strtolower(trim($incident->title)) === strtolower(trim($current->title)))
            ->count();
    }

    public function mostCommonResolution(): ?string
    {
        if ($this->mostCommonResolution !== null) {
            return $this->mostCommonResolution;
        }

        $resolution = $this->closedIncidents()
            ->groupBy(fn (Incident $incident) => strtolower(trim($incident->status->label())))
            ->sortByDesc(fn ($group) => $group->count())
            ->keys()
            ->first();

        return $this->mostCommonResolution = is_string($resolution) ? ucfirst($resolution) : null;
    }

    /**
     * @return list<string>
     */
    public function topRecommendedFixes(): array
    {
        if ($this->topRecommendedFixes !== null) {
            return $this->topRecommendedFixes;
        }

        return $this->topRecommendedFixes = $this->closedIncidents()
            ->groupBy(fn (Incident $incident) => strtolower(trim($incident->category)).':'.strtolower(trim($incident->title)))
            ->sortByDesc(fn ($group) => $group->count())
            ->take(3)
            ->map(fn ($group, string $key) => ucfirst(trim(explode(':', $key, 2)[1] ?? $key)))
            ->values()
            ->all();
    }
}
