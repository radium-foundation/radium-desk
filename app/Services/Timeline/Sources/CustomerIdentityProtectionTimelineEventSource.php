<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderIdentityProtectionService;
use Illuminate\Support\Collection;

class CustomerIdentityProtectionTimelineEventSource implements TimelineEventSource
{
    /**
     * @var array<string, array{at: string, by: string}>
     */
    private const FIELD_LOCK_COLUMNS = [
        'customer_name' => [
            'at' => 'customer_name_locked_at',
            'by' => 'customer_name_locked_by',
        ],
        'customer_phone' => [
            'at' => 'customer_phone_locked_at',
            'by' => 'customer_phone_locked_by',
        ],
        'customer_email' => [
            'at' => 'customer_email_locked_at',
            'by' => 'customer_email_locked_by',
        ],
    ];

    public function __construct(
        private readonly Order $order,
        private readonly OrderIdentityProtectionService $identityProtection,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $events = collect();

        foreach (self::FIELD_LOCK_COLUMNS as $field => $columns) {
            $lockedAt = $this->order->{$columns['at']};

            if ($lockedAt === null) {
                continue;
            }

            $title = $this->identityProtection->protectionTitleForField($field);

            if ($title === null) {
                continue;
            }

            $lockedBy = $this->order->{$columns['by']} !== null
                ? User::query()->find($this->order->{$columns['by']})
                : null;

            $actorName = trim((string) ($lockedBy?->name ?? 'Agent'));

            $events->push(new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $lockedAt,
                title: $title,
                actor: new TimelineActor(
                    displayName: $actorName,
                    subtitle: 'Agent action',
                    isAutomation: false,
                    kind: TimelineActorKind::Agent,
                ),
                dedupeKey: 'identity-protection:'.$field.':'.$this->order->id,
                summary: 'Manual correction protected this field from automated overwrites.',
                detail: null,
                summaryFields: [],
                filterTags: ['customer', 'protection'],
            ));
        }

        return $events
            ->sortByDesc(fn (TimelineEvent $event) => $event->occurredAt)
            ->when($limit !== null, fn (Collection $collection) => $collection->take($limit))
            ->values();
    }
}
