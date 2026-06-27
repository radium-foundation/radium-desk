<?php

namespace App\Services;

use App\Data\OrderCorrectionChange;
use App\Data\OrderTimelineEntry;
use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\User;
use Illuminate\Support\Collection;

class OrderActivityTimelineService
{
    private const CORRECTION_METADATA_KEY = 'correction_reason';

    /**
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'order_id' => 'Order ID',
        'serial_number' => 'Serial Number',
        'product_name' => 'Product',
        'device_model' => 'Model',
        'customer_name' => 'Customer Name',
        'customer_email' => 'Customer Email',
        'customer_phone' => 'Customer Phone',
        'status' => 'Status',
    ];

    /**
     * @var list<string>
     */
    private const FIELD_ORDER = [
        'order_id',
        'serial_number',
        'product_name',
        'device_model',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
    ];

    public function forOrder(Order $order): Collection
    {
        $order->loadMissing([
            'incidents.creator',
            'incidents.assignee',
        ]);

        $incidentIds = $order->incidents->pluck('id');
        $refundIds = $order->refundRequests()->pluck('id');
        $incidentsById = $order->incidents->keyBy('id');
        $remarkIds = $this->remarkIdsForOrder($order, $incidentIds, $refundIds);
        $approvalIds = ApprovalNumber::query()
            ->whereHas('incidents', fn ($query) => $query->where('order_id', $order->id))
            ->pluck('id');

        $entries = collect();

        foreach ($order->incidents as $incident) {
            if ($incident->created_at === null) {
                continue;
            }

            $entries->push(new OrderTimelineEntry(
                occurredAt: $incident->created_at,
                title: "Service Case {$incident->reference_no} created",
                detail: null,
                actorName: $incident->creator?->firstName(),
                dedupeKey: "incident-created:{$incident->id}",
            ));
        }

        $auditLogs = AuditLog::query()
            ->with(['user.roles'])
            ->where(function ($query) use ($order, $incidentIds, $refundIds, $remarkIds, $approvalIds) {
                $query->where(function ($orderQuery) use ($order) {
                    $orderQuery->where('auditable_type', $order->getMorphClass())
                        ->where('auditable_id', $order->id);
                });

                if ($incidentIds->isNotEmpty()) {
                    $query->orWhere(function ($incidentQuery) use ($incidentIds) {
                        $incidentQuery->where('auditable_type', (new Incident)->getMorphClass())
                            ->whereIn('auditable_id', $incidentIds);
                    });
                }

                if ($refundIds->isNotEmpty()) {
                    $query->orWhere(function ($refundQuery) use ($refundIds) {
                        $refundQuery->where('auditable_type', (new RefundRequest)->getMorphClass())
                            ->whereIn('auditable_id', $refundIds);
                    });
                }

                if ($remarkIds->isNotEmpty()) {
                    $query->orWhere(function ($remarkQuery) use ($remarkIds) {
                        $remarkQuery->where('auditable_type', (new Remark)->getMorphClass())
                            ->whereIn('auditable_id', $remarkIds)
                            ->where('event', 'created');
                    });
                }

                if ($approvalIds->isNotEmpty()) {
                    $query->orWhere(function ($approvalQuery) use ($approvalIds) {
                        $approvalQuery->where('auditable_type', (new ApprovalNumber)->getMorphClass())
                            ->whereIn('auditable_id', $approvalIds)
                            ->whereIn('event', ['incident_linked', 'deleted']);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->get();

        $approvalNumbers = ApprovalNumber::query()
            ->whereIn('id', $approvalIds)
            ->get()
            ->keyBy('id');

        foreach ($auditLogs as $auditLog) {
            foreach ($this->mapAuditLogEntries($auditLog, $incidentsById, $approvalNumbers, $incidentIds) as $entry) {
                $entries->push($entry);
            }
        }

        return $entries
            ->unique(fn (OrderTimelineEntry $entry) => $entry->dedupeKey)
            ->sortByDesc(fn (OrderTimelineEntry $entry) => $entry->occurredAt->timestamp)
            ->values();
    }

    /**
     * @param  Collection<int, Incident>  $incidentsById
     * @param  Collection<int, ApprovalNumber>  $approvalNumbers
     * @param  Collection<int, int|string>  $orderIncidentIds
     * @return Collection<int, OrderTimelineEntry>
     */
    private function mapAuditLogEntries(
        AuditLog $auditLog,
        Collection $incidentsById,
        Collection $approvalNumbers,
        Collection $orderIncidentIds,
    ): Collection {
        $occurredAt = $auditLog->created_at ?? now();

        if ($auditLog->auditable_type === (new Order)->getMorphClass()) {
            return match ($auditLog->event) {
                'transaction.assigned' => collect([
                    new OrderTimelineEntry(
                        occurredAt: $occurredAt,
                        title: 'Transaction ID added',
                        detail: (string) ($auditLog->new_values['transaction_id'] ?? ''),
                        actorName: $auditLog->user?->firstName(),
                        dedupeKey: "audit:{$auditLog->id}",
                    ),
                ]),
                'order.updated' => $this->mapOrderCorrectionEntries(
                    $auditLog,
                    $auditLog->user?->roleActorLabel() ?: $auditLog->user?->firstName(),
                    $occurredAt,
                ),
                default => collect(),
            };
        }

        $entry = $this->mapNonOrderAuditLogEntry(
            $auditLog,
            $incidentsById,
            $approvalNumbers,
            $orderIncidentIds,
            $occurredAt,
        );

        return $entry !== null ? collect([$entry]) : collect();
    }

    /**
     * @return Collection<int, OrderTimelineEntry>
     */
    private function mapOrderCorrectionEntries(
        AuditLog $auditLog,
        ?string $actorName,
        $occurredAt,
    ): Collection {
        $oldValues = $auditLog->old_values ?? [];
        $newValues = $auditLog->new_values ?? [];
        $reason = (string) ($newValues[self::CORRECTION_METADATA_KEY] ?? '');

        $changedFields = collect($newValues)
            ->except([self::CORRECTION_METADATA_KEY])
            ->keys()
            ->sortBy(fn (string $field): int => array_search($field, self::FIELD_ORDER, true) !== false
                ? array_search($field, self::FIELD_ORDER, true)
                : 999)
            ->values();

        $changes = $changedFields
            ->map(function (string $field) use ($oldValues, $newValues): OrderCorrectionChange {
                $label = self::FIELD_LABELS[$field] ?? str_replace('_', ' ', ucfirst($field));

                return new OrderCorrectionChange(
                    label: $label,
                    previous: $this->formatCorrectionValue($oldValues[$field] ?? null),
                    next: $this->formatCorrectionValue($newValues[$field] ?? null),
                );
            })
            ->all();

        if ($changes === []) {
            return collect();
        }

        return collect([
            new OrderTimelineEntry(
                occurredAt: $occurredAt,
                title: 'Updated Order Information',
                detail: null,
                actorName: $actorName,
                dedupeKey: "audit:{$auditLog->id}",
                correctionChanges: $changes,
                correctionReason: $reason !== '' ? $reason : null,
            ),
        ]);
    }

    private function formatCorrectionValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($value === 'active' || $value === 'archived') {
            return ucfirst((string) $value);
        }

        return (string) $value;
    }

    /**
     * @param  Collection<int, Incident>  $incidentsById
     * @param  Collection<int, ApprovalNumber>  $approvalNumbers
     * @param  Collection<int, int|string>  $orderIncidentIds
     */
    private function mapNonOrderAuditLogEntry(
        AuditLog $auditLog,
        Collection $incidentsById,
        Collection $approvalNumbers,
        Collection $orderIncidentIds,
        $occurredAt,
    ): ?OrderTimelineEntry {
        $displayActor = $auditLog->user?->firstName();

        if ($auditLog->auditable_type === (new Incident)->getMorphClass()) {
            $incident = $incidentsById->get($auditLog->auditable_id);

            return match ($auditLog->event) {
                'service_case.assigned' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Assigned to '.$this->assigneeFirstName($auditLog->new_values['assigned_to_user_id'] ?? null, $incident),
                    detail: $incident?->reference_no,
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'service_case.reassigned' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Reassigned to '.$this->assigneeFirstName($auditLog->new_values['assigned_to_user_id'] ?? null, $incident),
                    detail: $incident?->reference_no,
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                default => null,
            };
        }

        if ($auditLog->auditable_type === (new Remark)->getMorphClass()) {
            return $auditLog->event === 'created'
                ? new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Remark added',
                    detail: null,
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                )
                : null;
        }

        if ($auditLog->auditable_type === (new RefundRequest)->getMorphClass()) {
            return match ($auditLog->event) {
                'created' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Refund request created',
                    detail: (string) ($auditLog->new_values['reference_no'] ?? ''),
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'approved' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Refund request approved',
                    detail: (string) ($auditLog->new_values['reference_no'] ?? $auditLog->old_values['reference_no'] ?? ''),
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'rejected' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Refund request rejected',
                    detail: (string) ($auditLog->new_values['reference_no'] ?? $auditLog->old_values['reference_no'] ?? ''),
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                default => null,
            };
        }

        if ($auditLog->auditable_type === (new ApprovalNumber)->getMorphClass()) {
            $approval = $approvalNumbers->get($auditLog->auditable_id);
            $linkedIncidentId = $auditLog->new_values['incident_id'] ?? null;

            if ($auditLog->event === 'incident_linked' && $linkedIncidentId !== null) {
                if (! $orderIncidentIds->contains($linkedIncidentId)) {
                    return null;
                }
            }

            return match ($auditLog->event) {
                'incident_linked' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Approval linked',
                    detail: $approval?->approval_number,
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'deleted' => new OrderTimelineEntry(
                    occurredAt: $occurredAt,
                    title: 'Approval closed',
                    detail: (string) ($auditLog->old_values['approval_number'] ?? $approval?->approval_number ?? ''),
                    actorName: $displayActor,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                default => null,
            };
        }

        return null;
    }

    private function assigneeFirstName(mixed $userId, ?Incident $incident): string
    {
        if ($userId) {
            $user = User::query()->find($userId);

            if ($user !== null) {
                return $user->firstName();
            }
        }

        return $incident?->assignee?->firstName() ?? 'Admin';
    }

    /**
     * @param  Collection<int, int|string>  $incidentIds
     * @param  Collection<int, int|string>  $refundIds
     * @return Collection<int, int>
     */
    private function remarkIdsForOrder(Order $order, Collection $incidentIds, Collection $refundIds): Collection
    {
        return Remark::query()
            ->where(function ($query) use ($order, $incidentIds, $refundIds) {
                $query->where(function ($orderQuery) use ($order) {
                    $orderQuery->where('remarkable_type', $order->getMorphClass())
                        ->where('remarkable_id', $order->id);
                });

                if ($incidentIds->isNotEmpty()) {
                    $query->orWhere(function ($incidentQuery) use ($incidentIds) {
                        $incidentQuery->where('remarkable_type', (new Incident)->getMorphClass())
                            ->whereIn('remarkable_id', $incidentIds);
                    });
                }

                if ($refundIds->isNotEmpty()) {
                    $query->orWhere(function ($refundQuery) use ($refundIds) {
                        $refundQuery->where('remarkable_type', (new RefundRequest)->getMorphClass())
                            ->whereIn('remarkable_id', $refundIds);
                    });
                }
            })
            ->pluck('id');
    }
}
