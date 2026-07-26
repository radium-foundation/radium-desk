<?php

namespace App\Support\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\TeamActivityEntry;
use App\Models\AuditLog;
use Illuminate\Support\Str;

class TeamActivityEntryPresenter
{
    public function __construct(
        private readonly TeamActivityLabelFormatter $labelFormatter,
    ) {}

    /**
     * @param  array<int, RecentActivityItem>  $itemsByAuditId
     */
    public function fromAudit(AuditLog $audit, array $itemsByAuditId): ?TeamActivityEntry
    {
        $item = $itemsByAuditId[(int) $audit->id] ?? null;

        if (! $item instanceof RecentActivityItem || $audit->created_at === null) {
            return null;
        }

        $label = $this->labelFormatter->labelFor($audit, $item);

        $showEmbeddedReference = str_starts_with($label, 'Assigned ')
            || str_starts_with($label, 'Reassigned ')
            || str_starts_with($label, 'Escalated ');

        return new TeamActivityEntry(
            at: $audit->created_at,
            time: $audit->created_at->format('H:i'),
            label: $label,
            reference: $showEmbeddedReference ? null : ($item->incidentLabel() ?: null),
            incidentId: $item->entityIncidentId,
            serviceCaseReference: $item->incidentReference,
            orderReference: $item->orderReference,
            description: $this->descriptionFor($audit, $item),
        );
    }

    private function descriptionFor(AuditLog $audit, RecentActivityItem $item): ?string
    {
        $newValues = is_array($audit->new_values) ? $audit->new_values : [];
        $oldValues = is_array($audit->old_values) ? $audit->old_values : [];

        $description = match ($audit->event) {
            'created' => filled($newValues['body'] ?? null)
                ? Str::limit((string) $newValues['body'], 120)
                : null,
            'deleted' => filled($oldValues['body'] ?? null)
                ? Str::limit((string) $oldValues['body'], 120)
                : null,
            'service_case.status_changed' => filled($newValues['status'] ?? null)
                ? 'Status → '.(string) $newValues['status']
                : null,
            'service_case.assigned', 'service_case.reassigned' => filled($newValues['assigned_to_user_id'] ?? null)
                ? 'Assigned to user #'.(int) $newValues['assigned_to_user_id']
                : null,
            'whatsapp.template_sent' => filled($newValues['template_key'] ?? null)
                ? 'Template: '.(string) $newValues['template_key']
                : null,
            'incoming_email.promoted_to_service_case' => 'Historical email linked to new service case',
            'approval_numbers.submitted' => isset($newValues['count'])
                ? (int) $newValues['count'].' approval number(s) saved'
                : 'Approval numbers saved',
            'approval_numbers.deleted' => 'Approval number removed',
            'refund.approved', 'refund.rejected', 'refund.completed' => filled($item->customerName)
                ? (string) $item->customerName
                : null,
            'service_case.automation.validation_passed' => 'Automation validation completed successfully',
            default => filled($item->customerName) ? (string) $item->customerName : null,
        };

        if ($description !== null && $description !== '') {
            return $description;
        }

        if (filled($item->customerName)) {
            return (string) $item->customerName;
        }

        return null;
    }
}
