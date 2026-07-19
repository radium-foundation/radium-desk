<?php

namespace App\Support\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\RecentActivityStreams;
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
        $showSystem = $viewer->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return new RecentActivityStreams(
            customer: ($grouped->get('customer') ?? collect())->take($perStream)->values(),
            agentAdmin: ($grouped->get('agent_admin') ?? collect())->take($perStream)->values(),
            system: $showSystem
                ? ($grouped->get('system') ?? collect())->take($perStream)->values()
                : collect(),
            showSystem: $showSystem,
        );
    }

    /**
     * @return list<string>
     */
    public static function eagerLoadRelations(): array
    {
        return [
            'user',
            'auditable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Order::class => ['incidents:id,order_id,reference_no,updated_at'],
                Remark::class => ['remarkable'],
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

        return new MappedRecentActivity(
            event: $auditLog->event,
            stream: $this->resolveStream($auditLog, $config),
            title: $presentation['title'],
            icon: $presentation['icon'],
            sourceBadge: $presentation['source'],
            indicatorVariant: $presentation['variant'],
            entityLabel: $entity['label'] ?? null,
            entityIncidentId: $entity['incident_id'] ?? null,
            entityReference: $entity['reference'] ?? null,
            occurredAt: $auditLog->created_at,
            relativeTime: AppDateFormatter::timelineOperatorRelative($auditLog->created_at) ?? '—',
            exactTime: AppDateFormatter::timelineDatetime($auditLog->created_at) ?? '—',
            actorName: $actor->compactLabel() !== '' ? $actor->compactLabel() : 'System',
            actorIconClass: $actor->iconClass(),
            isAutomation: $actor->isAutomationIdentity(),
            actorUser: $actor->isAutomationIdentity() ? null : $auditLog->user,
            includes: $presentation['includes'],
            groupKey: $this->groupKeyForEvent($auditLog->event),
            auditableKey: $this->auditableKey($auditLog),
            includeLabel: $presentation['include_label'],
        );
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
            ? (string) config('dashboard-activity.fallback_streams.automation_actor', 'system')
            : (string) config('dashboard-activity.fallback_streams.human_actor', 'agent_admin');
    }

    /**
     * @return array{title: string, icon: string, source: ?string, variant: string, includes: list<string>, include_label: ?string}|null
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
                    'icon' => '💬',
                    'source' => 'Manual',
                    'variant' => 'warning',
                    'includes' => [],
                    'include_label' => null,
                ];
            }

            $actionLabel = trim((string) ($auditLog->new_values['action_label'] ?? ''));

            return [
                'title' => $actionLabel !== '' ? $actionLabel : (string) $config['title'],
                'icon' => (string) $config['icon'],
                'source' => $this->resolveSourceBadge($auditLog, $config),
                'variant' => (string) $config['variant'],
                'includes' => [],
                'include_label' => $this->communicationIncludeLabel($auditLog),
            ];
        }

        if ($auditLog->event === 'notification.dispatched') {
            $aggregateSuccess = (bool) ($auditLog->new_values['aggregate_success'] ?? true);

            return [
                'title' => $aggregateSuccess ? 'Notification Sent' : 'Notification Failed',
                'icon' => '🔔',
                'source' => $this->resolveSourceBadge($auditLog, $config),
                'variant' => $aggregateSuccess ? (string) $config['variant'] : 'error',
                'includes' => [],
                'include_label' => $this->notificationIncludeLabel($auditLog),
            ];
        }

        if ($auditLog->event === 'service_case.status_changed') {
            $statusLabel = trim((string) ($auditLog->new_values['status_label'] ?? ''));

            return [
                'title' => $statusLabel !== '' ? 'Status: '.$statusLabel : (string) $config['title'],
                'icon' => (string) $config['icon'],
                'source' => $this->resolveSourceBadge($auditLog, $config),
                'variant' => (string) $config['variant'],
                'includes' => [],
                'include_label' => null,
            ];
        }

        return [
            'title' => (string) $config['title'],
            'icon' => (string) $config['icon'],
            'source' => $this->resolveSourceBadge($auditLog, $config),
            'variant' => (string) $config['variant'],
            'includes' => [],
            'include_label' => null,
        ];
    }

    /**
     * @return array{title: string, icon: string, source: ?string, variant: string, stream?: string, hidden?: bool, remark_only?: bool}|null
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
     * @return array{title: string, icon: string, source: ?string, variant: string}
     */
    private function fallbackConfig(AuditLog $auditLog): array
    {
        $isAutomation = $this->automationIdentity->isAutomationActor($auditLog->user);

        return [
            'title' => $this->humanizeEventName($auditLog->event),
            'icon' => '📋',
            'source' => $isAutomation ? 'Automation' : 'Manual',
            'variant' => $isAutomation ? 'automation' : 'muted',
        ];
    }

    private function humanizeEventName(string $event): string
    {
        $normalized = str_replace(['.', '_'], ' ', $event);

        return Str::title(trim($normalized));
    }

    /**
     * @param  array{source: ?string}  $config
     */
    private function resolveSourceBadge(AuditLog $auditLog, array $config): ?string
    {
        if (($config['source'] ?? null) !== null) {
            return (string) $config['source'];
        }

        if ($auditLog->event === 'communication_action.lifecycle') {
            $executionMode = (string) ($auditLog->new_values['execution_mode'] ?? '');

            return match ($executionMode) {
                'automatic' => 'Automation',
                'manual' => 'Manual',
                default => null,
            };
        }

        $channels = $this->notificationChannels($auditLog);

        if ($channels !== []) {
            return $channels[0];
        }

        return $this->automationIdentity->isAutomationActor($auditLog->user) ? 'Automation' : 'Manual';
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

    private function notificationIncludeLabel(AuditLog $auditLog): string
    {
        return 'Notification';
    }

    private function communicationIncludeLabel(AuditLog $auditLog): ?string
    {
        $channels = $this->notificationChannels($auditLog);

        if ($channels !== []) {
            return $channels[0];
        }

        $actionLabel = trim((string) ($auditLog->new_values['action_label'] ?? ''));

        return $actionLabel !== '' ? $actionLabel : 'Communication';
    }

    /**
     * @return array{label: ?string, incident_id: ?int, reference: ?string}
     */
    private function resolveEntity(AuditLog $auditLog): array
    {
        $incident = $this->resolveIncident($auditLog);
        $label = $this->resolveEntityLabel($auditLog, $incident);

        return [
            'label' => $label,
            'incident_id' => $incident?->id,
            'reference' => $incident?->display_reference ?: $incident?->reference_no,
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

    private function resolveEntityLabel(AuditLog $auditLog, ?Incident $incident): ?string
    {
        $auditable = $auditLog->auditable;

        if ($auditable instanceof Incident) {
            return filled($auditable->display_reference)
                ? 'Incident '.$auditable->display_reference
                : 'Incident #'.$auditable->id;
        }

        if ($auditable instanceof Order) {
            $orderNumber = filled($auditable->order_id)
                ? (string) $auditable->order_id
                : '#'.$auditable->id;

            return 'Order '.$orderNumber;
        }

        if ($auditable instanceof Remark) {
            return 'Remark #'.$auditable->id;
        }

        if ($auditLog->auditable_type === (new Incident)->getMorphClass() && $auditLog->auditable_id !== null) {
            return $incident && filled($incident->display_reference)
                ? 'Incident '.$incident->display_reference
                : 'Incident #'.$auditLog->auditable_id;
        }

        if ($auditLog->auditable_type === (new Order)->getMorphClass() && $auditLog->auditable_id !== null) {
            $orderId = (string) ($auditLog->new_values['order_id'] ?? '#'.$auditLog->auditable_id);

            return 'Order '.$orderId;
        }

        if ($auditLog->auditable_type === (new Remark)->getMorphClass() && $auditLog->auditable_id !== null) {
            return 'Remark #'.$auditLog->auditable_id;
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
        $icon = (string) ($configuredGroup['icon'] ?? '💬');
        $variant = (string) ($configuredGroup['variant'] ?? 'communication');
        $stream = (string) ($configuredGroup['stream'] ?? $items[0]->stream);

        $includes = collect($items)
            ->map(fn (MappedRecentActivity $item): ?string => $item->includeLabel ?? $item->sourceBadge)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $latest = collect($items)->sortByDesc(fn (MappedRecentActivity $item): int => $item->occurredAt->timestamp)->first();

        return new RecentActivityItem(
            stream: $stream,
            title: $title,
            icon: $icon,
            sourceBadge: null,
            indicatorVariant: $variant,
            entityLabel: $latest->entityLabel,
            entityIncidentId: $latest->entityIncidentId,
            entityReference: $latest->entityReference,
            occurredAt: $latest->occurredAt,
            relativeTime: $latest->relativeTime,
            exactTime: $latest->exactTime,
            actorName: $latest->actorName,
            actorIconClass: $latest->actorIconClass,
            isAutomation: $latest->isAutomation,
            actorUser: $latest->actorUser,
            includes: $includes,
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
    /**
     * @param  list<string>  $includes
     */
    public function __construct(
        public string $event,
        public string $stream,
        public string $title,
        public string $icon,
        public ?string $sourceBadge,
        public string $indicatorVariant,
        public ?string $entityLabel,
        public ?int $entityIncidentId,
        public ?string $entityReference,
        public Carbon $occurredAt,
        public string $relativeTime,
        public string $exactTime,
        public string $actorName,
        public string $actorIconClass,
        public bool $isAutomation,
        public ?User $actorUser,
        public array $includes,
        public ?string $groupKey,
        public string $auditableKey,
        public ?string $includeLabel,
    ) {}

    public function toItem(): RecentActivityItem
    {
        return new RecentActivityItem(
            stream: $this->stream,
            title: $this->title,
            icon: $this->icon,
            sourceBadge: $this->sourceBadge,
            indicatorVariant: $this->indicatorVariant,
            entityLabel: $this->entityLabel,
            entityIncidentId: $this->entityIncidentId,
            entityReference: $this->entityReference,
            occurredAt: $this->occurredAt,
            relativeTime: $this->relativeTime,
            exactTime: $this->exactTime,
            actorName: $this->actorName,
            actorIconClass: $this->actorIconClass,
            isAutomation: $this->isAutomation,
            actorUser: $this->actorUser,
            includes: $this->includes,
        );
    }
}
