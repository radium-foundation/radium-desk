<?php

namespace App\Support\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\RecentActivityStreams;
use App\Data\RecentActivityThread;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Enums\NotificationChannelType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\AutomationIdentityService;
use App\Support\AppDateFormatter;
use App\Support\Timeline\TimelineActorPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RecentActivityPresenter
{
    /** @var array<string, string> */
    private array $groupEventIndex;

    public function __construct(
        private readonly AutomationIdentityService $automationIdentity,
    ) {
        $this->groupEventIndex = $this->buildGroupEventIndex();
    }

    /**
     * @param  Collection<int, AuditLog>  $auditLogs
     */
    public function presentStreams(Collection $auditLogs, User $viewer, ?int $perStreamLimit = null): RecentActivityStreams
    {
        $perStream = $perStreamLimit ?? (int) config('dashboard-activity.limits.per_stream', 12);

        $items = $this->collapseGroups(
            $auditLogs
                ->map(fn (AuditLog $auditLog): ?MappedRecentActivity => $this->mapAuditLog($auditLog))
                ->filter()
                ->values(),
        );

        $grouped = $items->groupBy(fn (RecentActivityItem $item): string => $item->stream);
        $showIra = $viewer->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return new RecentActivityStreams(
            customer: $this->threadItems(($grouped->get('customer') ?? collect())->take($perStream)->values()),
            team: $this->threadItems(($grouped->get('team') ?? collect())->take($perStream)->values()),
            ira: $showIra
                ? $this->threadItems(($grouped->get('ira') ?? collect())->take($perStream)->values())
                : collect(),
            showIra: $showIra,
        );
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
            ]),
        ];
    }

    private function mapAuditLog(AuditLog $auditLog): ?MappedRecentActivity
    {
        if ($auditLog->created_at === null) {
            return null;
        }

        $config = $this->resolveEventConfig($auditLog);

        if ($config === null) {
            return null;
        }

        $presentation = $this->resolvePresentation($auditLog, $config);

        if ($presentation === null) {
            return null;
        }

        $entity = $this->resolveEntity($auditLog);
        $actor = TimelineActorPresenter::for(
            $this->automationIdentity->resolve($auditLog->user),
        );
        $isAutomation = $actor->isAutomationIdentity();
        $stream = $this->enforceStreamPurity(
            $this->resolveStream($auditLog, $config),
            $isAutomation,
            (bool) ($config['allow_automation_actor'] ?? false),
        );

        if ($stream === null) {
            return null;
        }

        return new MappedRecentActivity(
            event: $auditLog->event,
            stream: $stream,
            title: $presentation['title'],
            typePill: $presentation['pill'],
            indicatorVariant: $presentation['variant'],
            incidentReference: $entity['reference'],
            orderReference: $entity['order_reference'],
            customerName: $entity['customer_name'],
            entityIncidentId: $entity['incident_id'],
            entityReference: $entity['reference'],
            occurredAt: $auditLog->created_at,
            compactTime: AppDateFormatter::activityFeedCompact($auditLog->created_at) ?? '—',
            exactTime: AppDateFormatter::timelineDatetime($auditLog->created_at) ?? '—',
            actorName: $this->resolveActorLabel($actor, $stream),
            isAutomation: $isAutomation,
            groupKey: $this->groupKeyForEvent($auditLog->event),
            auditableKey: $this->auditableKey($auditLog),
            includePill: $presentation['include_pill'],
        );
    }

    private function enforceStreamPurity(string $stream, bool $isAutomation, bool $allowAutomationInCustomer): ?string
    {
        $stream = match ($stream) {
            'agent_admin' => 'team',
            'system' => 'ira',
            default => $stream,
        };

        if ($stream === 'customer' && $isAutomation && ! $allowAutomationInCustomer) {
            return 'ira';
        }

        if ($stream === 'team' && $isAutomation) {
            return 'ira';
        }

        return $stream;
    }

    /**
     * @param  array{stream?: string, stream_when_automation_actor?: string}  $config
     */
    private function resolveStream(AuditLog $auditLog, array $config): string
    {
        if (isset($config['stream'])) {
            if (isset($config['stream_when_automation_actor'])
                && $this->automationIdentity->isAutomationActor($auditLog->user)) {
                return (string) $config['stream_when_automation_actor'];
            }

            return (string) $config['stream'];
        }

        return $this->automationIdentity->isAutomationActor($auditLog->user)
            ? (string) config('dashboard-activity.fallback_streams.automation_actor', 'ira')
            : (string) config('dashboard-activity.fallback_streams.human_actor', 'team');
    }

    private function resolveActorLabel(TimelineActorPresenter $actor, string $stream): string
    {
        if ($stream === 'ira' || $actor->isAutomationIdentity()) {
            return 'IRA';
        }

        $label = $actor->compactLabel();

        return $label !== '' ? $label : 'System';
    }

    /**
     * @return array{title: string, pill: ?string, variant: string, include_pill: ?string}|null
     */
    private function resolvePresentation(AuditLog $auditLog, array $config): ?array
    {
        if ($auditLog->event === 'communication_action.lifecycle') {
            $status = CommunicationActionLifecycleStatus::tryFrom(
                (string) ($auditLog->new_values['status'] ?? ''),
            );

            if ($status === CommunicationActionLifecycleStatus::Opened
                || $status === CommunicationActionLifecycleStatus::Completed
                || $status === CommunicationActionLifecycleStatus::Available) {
                return null;
            }

            if ($status === CommunicationActionLifecycleStatus::Skipped) {
                return [
                    'title' => 'Communication Skipped',
                    'pill' => 'Communication',
                    'variant' => 'warning',
                    'include_pill' => null,
                ];
            }

            $channelPill = $this->communicationIncludePill($auditLog);

            return [
                'title' => (string) $config['title'],
                'pill' => $channelPill ?? $this->resolveTypePill($auditLog, $config),
                'variant' => (string) $config['variant'],
                'include_pill' => $channelPill,
            ];
        }

        if ($auditLog->event === 'notification.dispatched') {
            $aggregateSuccess = (bool) ($auditLog->new_values['aggregate_success'] ?? true);
            $channelPill = $this->communicationIncludePill($auditLog);

            return [
                'title' => $aggregateSuccess ? 'Communication Sent' : 'Communication Failed',
                'pill' => $channelPill ?? $this->resolveTypePill($auditLog, $config),
                'variant' => $aggregateSuccess ? (string) $config['variant'] : 'error',
                'include_pill' => $channelPill ?? 'Notification',
            ];
        }

        return [
            'title' => (string) $config['title'],
            'pill' => $this->resolveTypePill($auditLog, $config),
            'variant' => (string) $config['variant'],
            'include_pill' => null,
        ];
    }

    /**
     * @param  array{pill?: string}  $config
     */
    private function resolveTypePill(AuditLog $auditLog, array $config): ?string
    {
        if (filled($config['pill'] ?? null)) {
            return (string) $config['pill'];
        }

        $channels = $this->notificationChannels($auditLog);

        return $channels[0] ?? null;
    }

    /**
     * @return array{title: string, pill?: string, variant: string, stream?: string, hidden?: bool, remark_only?: bool, allow_automation_actor?: bool}|null
     */
    private function resolveEventConfig(AuditLog $auditLog): ?array
    {
        $events = config('dashboard-activity.events', []);
        $config = $events[$auditLog->event] ?? null;

        if (is_array($config)) {
            if (($config['remark_only'] ?? false) === true
                && $auditLog->auditable_type !== (new Remark)->getMorphClass()) {
                return $this->fallbackConfig($auditLog);
            }

            if (($config['hidden'] ?? false) === true) {
                return null;
            }

            return $config;
        }

        return $this->fallbackConfig($auditLog);
    }

    /**
     * @return array{title: string, pill: string, variant: string}
     */
    private function fallbackConfig(AuditLog $auditLog): array
    {
        $isAutomation = $this->automationIdentity->isAutomationActor($auditLog->user);

        return [
            'title' => $this->humanizeEventName($auditLog->event),
            'pill' => $isAutomation ? 'IRA' : 'Activity',
            'variant' => $isAutomation ? 'automation' : 'muted',
        ];
    }

    private function humanizeEventName(string $event): string
    {
        $normalized = str_replace(['.', '_'], ' ', $event);

        return Str::title(trim($normalized));
    }

    /**
     * @return list<string>
     */
    private function notificationChannels(AuditLog $auditLog): array
    {
        $channels = collect($auditLog->new_values['channel_results'] ?? [])
            ->map(function (array $record): ?string {
                $channel = NotificationChannelType::tryFrom((string) ($record['channel'] ?? ''));

                return $channel?->label();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($channels !== []) {
            return $channels;
        }

        $lifecycleChannels = array_values(array_filter(
            (array) ($auditLog->new_values['channels'] ?? []),
            fn (mixed $channel): bool => is_string($channel) && $channel !== '',
        ));

        return collect($lifecycleChannels)
            ->map(function (string $channel): string {
                $typed = NotificationChannelType::tryFrom($channel);

                return $typed?->label() ?? Str::title(str_replace('_', ' ', $channel));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function communicationIncludePill(AuditLog $auditLog): ?string
    {
        $channels = $this->notificationChannels($auditLog);

        return $channels[0] ?? null;
    }

    /**
     * @return array{incident_id: ?int, reference: ?string, order_reference: ?string, customer_name: ?string}
     */
    private function resolveEntity(AuditLog $auditLog): array
    {
        $incident = $this->resolveIncident($auditLog);
        $incidentId = $incident?->id;

        if ($incidentId === null
            && $auditLog->auditable_type === (new Incident)->getMorphClass()
            && $auditLog->auditable_id !== null) {
            $incidentId = (int) $auditLog->auditable_id;
        }

        return [
            'incident_id' => $incidentId,
            'reference' => $this->formatIncidentReference($incident, $auditLog),
            'order_reference' => $this->resolveOrderReference($incident, $auditLog),
            'customer_name' => $this->resolveCustomerName($incident, $auditLog),
        ];
    }

    private function resolveIncident(AuditLog $auditLog): ?Incident
    {
        $auditable = $auditLog->auditable;

        if ($auditable instanceof Incident) {
            return $auditable;
        }

        if ($auditable instanceof Order) {
            return $this->latestIncidentForOrder($auditable);
        }

        if ($auditable instanceof Remark) {
            if ($auditable->remarkable instanceof Incident) {
                return $auditable->remarkable;
            }

            if ($auditable->remarkable instanceof Order) {
                return $this->latestIncidentForOrder($auditable->remarkable);
            }
        }

        return null;
    }

    private function latestIncidentForOrder(Order $order): ?Incident
    {
        if (! $order->relationLoaded('incidents')) {
            return null;
        }

        return $order->incidents
            ->sortByDesc(fn (Incident $incident): int => $incident->updated_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function formatIncidentReference(?Incident $incident, AuditLog $auditLog): ?string
    {
        if ($incident !== null) {
            if (filled($incident->display_reference)) {
                return (string) $incident->display_reference;
            }

            if (filled($incident->reference_no)) {
                return (string) $incident->reference_no;
            }

            return 'SC'.$incident->id;
        }

        if ($auditLog->auditable_type === (new Incident)->getMorphClass() && $auditLog->auditable_id !== null) {
            return 'SC'.$auditLog->auditable_id;
        }

        $auditable = $auditLog->auditable;

        if ($auditable instanceof Remark) {
            return 'Remark #'.$auditable->id;
        }

        if ($auditLog->auditable_type === (new Remark)->getMorphClass() && $auditLog->auditable_id !== null) {
            return 'Remark #'.$auditLog->auditable_id;
        }

        return null;
    }

    private function resolveOrderReference(?Incident $incident, AuditLog $auditLog): ?string
    {
        if ($incident !== null && $incident->relationLoaded('order') && filled($incident->order?->order_id)) {
            return (string) $incident->order->order_id;
        }

        $auditable = $auditLog->auditable;

        if ($auditable instanceof Order && filled($auditable->order_id)) {
            return (string) $auditable->order_id;
        }

        if ($auditable instanceof Remark) {
            if ($auditable->remarkable instanceof Order && filled($auditable->remarkable->order_id)) {
                return (string) $auditable->remarkable->order_id;
            }

            if ($auditable->remarkable instanceof Incident
                && $auditable->remarkable->relationLoaded('order')
                && filled($auditable->remarkable->order?->order_id)) {
                return (string) $auditable->remarkable->order->order_id;
            }
        }

        if ($auditLog->auditable_type === (new Order)->getMorphClass()) {
            $orderId = $auditLog->new_values['order_id'] ?? null;

            return filled($orderId) ? (string) $orderId : null;
        }

        return null;
    }

    private function resolveCustomerName(?Incident $incident, AuditLog $auditLog): ?string
    {
        if ($incident !== null && $incident->relationLoaded('order') && filled($incident->order?->customer_name)) {
            return (string) $incident->order->customer_name;
        }

        $auditable = $auditLog->auditable;

        if ($auditable instanceof Order && filled($auditable->customer_name)) {
            return (string) $auditable->customer_name;
        }

        if ($auditable instanceof Remark) {
            if ($auditable->remarkable instanceof Order && filled($auditable->remarkable->customer_name)) {
                return (string) $auditable->remarkable->customer_name;
            }

            if ($auditable->remarkable instanceof Incident
                && $auditable->remarkable->relationLoaded('order')
                && filled($auditable->remarkable->order?->customer_name)) {
                return (string) $auditable->remarkable->order->customer_name;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, MappedRecentActivity>  $items
     * @return Collection<int, RecentActivityItem>
     */
    private function collapseGroups(Collection $items): Collection
    {
        $window = (int) config('dashboard-activity.collapse_window_seconds', 5);
        $result = collect();
        $buffer = [];

        foreach ($items as $item) {
            if ($item->groupKey === null) {
                if ($buffer !== []) {
                    $result->push($this->mergeGroup($buffer));
                    $buffer = [];
                }

                $result->push($item->toItem());

                continue;
            }

            if ($buffer === []) {
                $buffer[] = $item;

                continue;
            }

            $anchor = $buffer[0];
            $withinWindow = abs($item->occurredAt->diffInSeconds($anchor->occurredAt)) <= $window;
            $sameAuditable = $item->auditableKey === $anchor->auditableKey;
            $sameGroup = $item->groupKey === $anchor->groupKey;

            if ($withinWindow && $sameAuditable && $sameGroup) {
                $buffer[] = $item;

                continue;
            }

            $result->push($this->mergeGroup($buffer));
            $buffer = [$item];
        }

        if ($buffer !== []) {
            $result->push(count($buffer) === 1 ? $buffer[0]->toItem() : $this->mergeGroup($buffer));
        }

        return $result;
    }

    /**
     * @param  list<MappedRecentActivity>  $items
     */
    private function mergeGroup(array $items): RecentActivityItem
    {
        $groupKey = $items[0]->groupKey ?? 'communication';
        $configuredGroup = config('dashboard-activity.groups.'.$groupKey, []);
        $title = (string) ($configuredGroup['title'] ?? 'Communication Sent');
        $variant = (string) ($configuredGroup['variant'] ?? 'communication');
        $stream = (string) ($configuredGroup['stream'] ?? $items[0]->stream);

        $includePills = collect($items)
            ->map(fn (MappedRecentActivity $item): ?string => $item->includePill ?? $item->typePill)
            ->filter()
            ->unique()
            ->values();

        $pill = (string) ($configuredGroup['pill'] ?? 'Communication');
        $channelPill = $includePills->first(fn (string $label): bool => ! in_array($label, ['Notification', 'Communication'], true));

        if ($channelPill !== null) {
            $pill = $channelPill;
        } elseif ($includePills->isNotEmpty()) {
            $pill = (string) $includePills->first();
        }

        $latest = collect($items)->sortByDesc(fn (MappedRecentActivity $item): int => $item->occurredAt->timestamp)->first();

        return new RecentActivityItem(
            stream: $stream,
            title: $title,
            typePill: $pill,
            indicatorVariant: $variant,
            incidentReference: $latest->incidentReference,
            orderReference: $latest->orderReference,
            customerName: $latest->customerName,
            entityIncidentId: $latest->entityIncidentId,
            entityReference: $latest->entityReference,
            occurredAt: $latest->occurredAt,
            compactTime: $latest->compactTime,
            exactTime: $latest->exactTime,
            actorName: $latest->actorName,
            isAutomation: $latest->isAutomation,
        );
    }

    /**
     * @param  Collection<int, RecentActivityItem>  $items
     * @return Collection<int, RecentActivityThread>
     */
    private function threadItems(Collection $items): Collection
    {
        $threads = collect();
        $buffer = [];
        $lastIncidentId = null;

        foreach ($items as $item) {
            $incidentId = $item->entityIncidentId;

            if ($incidentId !== null && $incidentId === $lastIncidentId && $buffer !== []) {
                $buffer[] = $item;

                continue;
            }

            if ($buffer !== []) {
                $threads->push($this->makeThread($buffer));
            }

            $buffer = [$item];
            $lastIncidentId = $incidentId;
        }

        if ($buffer !== []) {
            $threads->push($this->makeThread($buffer));
        }

        return $threads;
    }

    /**
     * @param  list<RecentActivityItem>  $items
     */
    private function makeThread(array $items): RecentActivityThread
    {
        $first = $items[0];

        return new RecentActivityThread(
            incidentId: $first->entityIncidentId,
            incidentReference: $first->incidentReference,
            items: $items,
        );
    }

    private function groupKeyForEvent(string $event): ?string
    {
        return $this->groupEventIndex[$event] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildGroupEventIndex(): array
    {
        $index = [];

        foreach (config('dashboard-activity.groups', []) as $groupKey => $group) {
            foreach ($group['events'] ?? [] as $event) {
                $index[$event] = $groupKey;
            }
        }

        return $index;
    }

    private function auditableKey(AuditLog $auditLog): string
    {
        return sha1(($auditLog->auditable_type ?? '').':'.($auditLog->auditable_id ?? ''));
    }
}

/**
 * @internal Presentation pipeline value object.
 */
final class MappedRecentActivity
{
    public function __construct(
        public string $event,
        public string $stream,
        public string $title,
        public ?string $typePill,
        public string $indicatorVariant,
        public ?string $incidentReference,
        public ?string $orderReference,
        public ?string $customerName,
        public ?int $entityIncidentId,
        public ?string $entityReference,
        public Carbon $occurredAt,
        public string $compactTime,
        public string $exactTime,
        public string $actorName,
        public bool $isAutomation,
        public ?string $groupKey,
        public string $auditableKey,
        public ?string $includePill,
    ) {}

    public function toItem(): RecentActivityItem
    {
        return new RecentActivityItem(
            stream: $this->stream,
            title: $this->title,
            typePill: $this->typePill,
            indicatorVariant: $this->indicatorVariant,
            incidentReference: $this->incidentReference,
            orderReference: $this->orderReference,
            customerName: $this->customerName,
            entityIncidentId: $this->entityIncidentId,
            entityReference: $this->entityReference,
            occurredAt: $this->occurredAt,
            compactTime: $this->compactTime,
            exactTime: $this->exactTime,
            actorName: $this->actorName,
            isAutomation: $this->isAutomation,
        );
    }
}
