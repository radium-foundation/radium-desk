<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OrderMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $order = $context->incident->order;

        if (! $order instanceof Order) {
            return [];
        }

        $milestones = [];

        if ($order->isLegacyImported()) {
            $importedAt = $order->legacy_imported_at ?? $order->created_at ?? now();

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::OrderImported,
                title: 'Legacy order imported',
                timestamp: $importedAt instanceof Carbon ? $importedAt : Carbon::parse($importedAt),
                status: 'completed',
                actor: $order->legacyImporter?->firstName() ?: $order->legacyImporter?->name,
                source: 'order',
                confidence: 85,
            );
        } elseif ($order->created_at !== null) {
            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::OrderImported,
                title: CustomerJourneyMilestoneType::OrderImported->label(),
                timestamp: $order->created_at,
                status: 'completed',
                actor: null,
                source: 'order',
                confidence: 75,
            );
        }

        $legacyAudit = AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('event', 'legacy_order.imported')
            ->orderBy('created_at')
            ->first();

        if ($legacyAudit !== null && $legacyAudit->created_at !== null) {
            $agentName = (string) ($legacyAudit->new_values['agent_name'] ?? $legacyAudit->user?->firstName() ?? 'Agent');

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::OrderImported,
                title: 'Legacy order imported',
                timestamp: $legacyAudit->created_at,
                status: 'completed',
                actor: $agentName,
                source: 'audit',
                confidence: 95,
            );
        }

        return $this->dedupeByType($milestones);
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     * @return list<CustomerJourneyMilestoneDTO>
     */
    private function dedupeByType(array $milestones): array
    {
        if ($milestones === []) {
            return [];
        }

        return [
            collect($milestones)
                ->sortByDesc(fn (CustomerJourneyMilestoneDTO $milestone) => $milestone->confidence)
                ->first(),
        ];
    }
}
