<?php

namespace App\Support\Dashboard;

use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TeamActivityIncidentResolver
{
    public function incidentMorph(): string
    {
        return (new Incident)->getMorphClass();
    }

    public function orderMorph(): string
    {
        return (new Order)->getMorphClass();
    }

    public function remarkMorph(): string
    {
        return (new Remark)->getMorphClass();
    }

    public function refundMorph(): string
    {
        return (new RefundRequest)->getMorphClass();
    }

    public function approvalMorph(): string
    {
        return (new ApprovalNumber)->getMorphClass();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $directEvents
     * @return array<int, int>
     */
    public function distinctCaseCountsForUsers(
        array $userIds,
        Carbon $dayStart,
        array $directEvents,
        bool $includeManualWhatsApp,
        bool $includeManualRemarkCreated,
        bool $includeManualRemarkDeleted,
    ): array {
        $query = $this->casesWorkedRowsQuery(
            $userIds,
            $dayStart,
            $directEvents,
            $includeManualWhatsApp,
            $includeManualRemarkCreated,
            $includeManualRemarkDeleted,
        );

        if ($query === null) {
            return [];
        }

        ['sql' => $incidentIdSql, 'bindings' => $bindings] = $this->incidentIdExpression();

        $counts = [];

        foreach (
            $query
                ->select('al.user_id')
                ->selectRaw("COUNT(DISTINCT {$incidentIdSql}) as case_count", $bindings)
                ->groupBy('al.user_id')
                ->havingRaw("COUNT(DISTINCT {$incidentIdSql}) > 0", $bindings)
                ->pluck('case_count', 'al.user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $directEvents
     */
    public function casesWorkedRowsQuery(
        array $userIds,
        Carbon $dayStart,
        array $directEvents,
        bool $includeManualWhatsApp,
        bool $includeManualRemarkCreated,
        bool $includeManualRemarkDeleted,
    ): ?Builder {
        if ($userIds === [] || ($directEvents === [] && ! $includeManualWhatsApp && ! $includeManualRemarkCreated && ! $includeManualRemarkDeleted)) {
            return null;
        }

        $incidentMorph = $this->incidentMorph();
        $orderMorph = $this->orderMorph();
        $remarkMorph = $this->remarkMorph();
        $refundMorph = $this->refundMorph();
        $approvalMorph = $this->approvalMorph();
        ['sql' => $incidentIdSql, 'bindings' => $bindings] = $this->incidentIdExpression();

        return AuditLog::query()
            ->from('audit_logs as al')
            ->selectRaw('al.user_id')
            ->selectRaw($incidentIdSql.' as incident_id', $bindings)
            ->leftJoin('remarks as r', function ($join) use ($remarkMorph): void {
                $join->on('al.auditable_id', '=', 'r.id')
                    ->where('al.auditable_type', '=', $remarkMorph);
            })
            ->leftJoin('refund_requests as rr', function ($join) use ($refundMorph): void {
                $join->on('al.auditable_id', '=', 'rr.id')
                    ->where('al.auditable_type', '=', $refundMorph)
                    ->whereNull('rr.deleted_at');
            })
            ->leftJoin('approval_incident as ai', function ($join) use ($approvalMorph): void {
                $join->on('al.auditable_id', '=', 'ai.approval_number_id')
                    ->where('al.auditable_type', '=', $approvalMorph);
            })
            ->whereIn('al.user_id', $userIds)
            ->where('al.created_at', '>=', $dayStart)
            ->where(function (Builder $query) use (
                $directEvents,
                $includeManualWhatsApp,
                $includeManualRemarkCreated,
                $includeManualRemarkDeleted,
                $remarkMorph,
            ): void {
                if ($directEvents !== []) {
                    $query->whereIn('al.event', $directEvents);
                }

                if ($includeManualWhatsApp) {
                    $method = $directEvents === [] ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $whatsapp) use ($remarkMorph): void {
                        $whatsapp->where('al.event', 'whatsapp.template_sent')
                            ->where('al.new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value);
                    });
                }

                if ($includeManualRemarkCreated) {
                    $method = ($directEvents === [] && ! $includeManualWhatsApp) ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $remark) use ($remarkMorph): void {
                        $remark->where('al.event', 'created')
                            ->where('al.auditable_type', $remarkMorph)
                            ->where('al.new_values->origin', 'manual');
                    });
                }

                if ($includeManualRemarkDeleted) {
                    $method = ($directEvents === [] && ! $includeManualWhatsApp && ! $includeManualRemarkCreated) ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $remark) use ($remarkMorph): void {
                        $remark->where('al.event', 'deleted')
                            ->where('al.auditable_type', $remarkMorph)
                            ->where('al.old_values->origin', 'manual');
                    });
                }
            });
    }

    public function resolveIncident(AuditLog $auditLog): ?Incident
    {
        $explicitId = $this->explicitIncidentIdFromPayload($auditLog);

        if ($explicitId !== null) {
            return $this->findIncident($explicitId, $auditLog);
        }

        $auditable = $auditLog->auditable;

        if ($auditable instanceof Incident) {
            return $auditable;
        }

        if ($auditable instanceof RefundRequest) {
            return $auditable->incident;
        }

        if ($auditable instanceof ApprovalNumber) {
            return $this->resolveApprovalNumberIncident($auditable);
        }

        if ($auditable instanceof Remark) {
            return $this->resolveRemarkIncident($auditable);
        }

        if ($auditable instanceof Order) {
            return $this->resolveOrderIncident($auditable);
        }

        if ($auditLog->auditable_type === $this->incidentMorph() && $auditLog->auditable_id !== null) {
            return $this->findIncident((int) $auditLog->auditable_id, $auditLog);
        }

        return null;
    }

    public function resolveIncidentId(AuditLog $auditLog): ?int
    {
        return $this->resolveIncident($auditLog)?->id;
    }

    /**
     * @return list<string>
     */
    public static function eagerLoadRelations(): array
    {
        $orderColumns = 'id,order_id,customer_name';
        $incidentColumns = 'id,order_id,reference_no,updated_at';

        return [
            'user',
            'auditable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Incident::class => ['order:'.$orderColumns],
                Order::class => [
                    'incidents:'.$incidentColumns,
                ],
                Remark::class => [
                    'remarkable' => fn (MorphTo $remarkable) => $remarkable->morphWith([
                        Incident::class => ['order:'.$orderColumns],
                        Order::class => [
                            'incidents:'.$incidentColumns,
                        ],
                    ]),
                ],
                RefundRequest::class => [
                    'incident:'.$incidentColumns,
                ],
                ApprovalNumber::class => [
                    'incidents:'.$incidentColumns,
                ],
            ]),
        ];
    }

    private function explicitIncidentIdFromPayload(AuditLog $auditLog): ?int
    {
        foreach ([$auditLog->new_values, $auditLog->old_values] as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $incidentId = $payload['incident_id'] ?? null;

            if ($incidentId !== null && $incidentId !== '') {
                return (int) $incidentId;
            }
        }

        return null;
    }

    private function resolveRemarkIncident(Remark $remark): ?Incident
    {
        if ($remark->remarkable instanceof Incident) {
            return $remark->remarkable;
        }

        if ($remark->remarkable instanceof Order) {
            return $this->resolveOrderIncident($remark->remarkable);
        }

        return null;
    }

    private function resolveOrderIncident(Order $order): ?Incident
    {
        if (! $order->relationLoaded('incidents')) {
            return $order->incidents()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();
        }

        return $order->incidents
            ->sortByDesc(fn (Incident $incident): int => $incident->updated_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function resolveApprovalNumberIncident(ApprovalNumber $approvalNumber): ?Incident
    {
        if (! $approvalNumber->relationLoaded('incidents')) {
            return $approvalNumber->incidents()
                ->orderByDesc('approval_incident.created_at')
                ->orderByDesc('incidents.id')
                ->first();
        }

        return $approvalNumber->incidents
            ->sortByDesc(fn (Incident $incident): int => $incident->pivot?->created_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function findIncident(int $incidentId, AuditLog $auditLog): ?Incident
    {
        if ($auditLog->auditable instanceof Incident && (int) $auditLog->auditable->id === $incidentId) {
            return $auditLog->auditable;
        }

        return Incident::query()->find($incidentId);
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function incidentIdExpression(): array
    {
        $incidentMorph = $this->incidentMorph();
        $orderMorph = $this->orderMorph();
        $remarkMorph = $this->remarkMorph();
        $jsonIncidentId = $this->jsonIncidentIdSql('al.new_values');
        $latestOrderIncident = $this->latestIncidentIdForOrderSql('al.auditable_id');
        $latestRemarkOrderIncident = $this->latestIncidentIdForOrderSql('r.remarkable_id');

        return [
            'sql' => <<<SQL
COALESCE(
    CASE WHEN al.auditable_type = ? THEN al.auditable_id END,
    {$jsonIncidentId},
    CASE WHEN al.auditable_type = ? AND r.remarkable_type = ? THEN r.remarkable_id END,
    CASE WHEN al.auditable_type = ? AND r.remarkable_type = ? THEN {$latestRemarkOrderIncident} END,
    rr.incident_id,
    ai.incident_id,
    CASE WHEN al.auditable_type = ? THEN {$latestOrderIncident} END
)
SQL,
            'bindings' => [
                $incidentMorph,
                $remarkMorph,
                $incidentMorph,
                $remarkMorph,
                $orderMorph,
                $orderMorph,
            ],
        ];
    }

    private function jsonIncidentIdSql(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(json_extract({$column}, '$.incident_id') AS INTEGER)";
        }

        return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.incident_id')) AS UNSIGNED)";
    }

    private function latestIncidentIdForOrderSql(string $orderIdColumn): string
    {
        return '(SELECT i.id FROM incidents i WHERE i.order_id = '.$orderIdColumn.' ORDER BY i.updated_at DESC, i.id DESC LIMIT 1)';
    }
}
