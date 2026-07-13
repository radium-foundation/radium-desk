<?php

namespace App\Services\CommunicationActions;

use App\Contracts\CommunicationActions\CommunicationActionTargetProvider;
use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Enums\CommunicationActionKey;
use App\Enums\NotificationChannelType;
use App\Models\Incident;
use App\Models\User;
use InvalidArgumentException;

final class CommunicationActionTargetProviderRegistry
{
    /**
     * @param  list<CommunicationActionTargetProvider>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly CommunicationActionRegistry $communicationActionRegistry,
        private readonly CommunicationActionEligibilityService $eligibilityService,
        private readonly CommunicationActionAvailabilityService $availabilityService,
    ) {}

    /**
     * @return list<CommunicationActionKey>
     */
    public function centerActionKeys(): array
    {
        return [
            CommunicationActionKey::DriverInstallationGuide,
            CommunicationActionKey::ReviewRequest,
            CommunicationActionKey::BuyRdService,
            CommunicationActionKey::BuyProduct,
        ];
    }

    public function isCenterAction(CommunicationActionKey|string $key): bool
    {
        $resolved = $key instanceof CommunicationActionKey
            ? $key
            : CommunicationActionKey::tryFrom((string) $key);

        if ($resolved === null) {
            return false;
        }

        return in_array($resolved, $this->centerActionKeys(), true);
    }

    public function providerFor(CommunicationActionKey $key): CommunicationActionTargetProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($key)) {
                return $provider;
            }
        }

        throw new InvalidArgumentException("No target provider registered for [{$key->value}].");
    }

    public function hasProviderFor(CommunicationActionKey $key): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    public function eligibleCenterActions(Incident $incident, User $user): array
    {
        return collect($this->centerActionKeys())
            ->map(function (CommunicationActionKey $key) use ($incident, $user): ?array {
                $definition = $this->communicationActionRegistry->get($key);

                if (! $this->eligibilityService->canShowAction($definition, $incident, $user)) {
                    return null;
                }

                return [
                    'key' => $key->value,
                    'name' => $definition->name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function hasEligibleCenterAction(Incident $incident, User $user): bool
    {
        return $this->eligibleCenterActions($incident, $user) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCenterConfig(Incident $incident, User $user, ?string $selectedActionKey = null): array
    {
        $incident->loadMissing('order');
        $order = $incident->order;
        $eligibleActions = $this->eligibleCenterActions($incident, $user);

        if ($eligibleActions === []) {
            return [
                'actions' => [],
                'selectedActionKey' => null,
                'actionUrls' => [],
                'targetsByAction' => [],
                'targetGroupLabels' => [],
                'defaultTargets' => [],
                'channelAvailability' => [],
                'canSendByAction' => [],
            ];
        }

        $selectedActionKey ??= $eligibleActions[0]['key'];

        if (! collect($eligibleActions)->pluck('key')->contains($selectedActionKey)) {
            $selectedActionKey = $eligibleActions[0]['key'];
        }

        $actions = [];
        $actionUrls = [];
        $targetsByAction = [];
        $targetGroupLabels = [];
        $defaultTargets = [];
        $channelAvailability = [];
        $canSendByAction = [];

        foreach ($eligibleActions as $action) {
            $key = CommunicationActionKey::from($action['key']);
            $definition = $this->communicationActionRegistry->get($key);
            $provider = $this->providerFor($key);
            $availability = $this->availabilityService->forDefinition($definition, $order);

            $actions[] = $action;
            $actionUrls[$action['key']] = route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => $action['key'],
            ]);
            $targetsByAction[$action['key']] = collect($provider->targets($incident))
                ->map(fn ($target) => $target->toArray())
                ->values()
                ->all();
            $targetGroupLabels[$action['key']] = $provider->targetGroupLabel();
            $defaultTargets[$action['key']] = $provider->defaultTargetValue($incident);
            $channelAvailability[$action['key']] = $this->serializeChannelAvailability($definition, $availability);
            $canSendByAction[$action['key']] = $this->availabilityService->hasDeliverableChannel($availability);
        }

        $selectedDefinition = $this->communicationActionRegistry->get($selectedActionKey);
        $selectedAvailability = $this->availabilityService->forDefinition($selectedDefinition, $order);

        return [
            'actions' => $actions,
            'selectedActionKey' => $selectedActionKey,
            'actionUrls' => $actionUrls,
            'targetsByAction' => $targetsByAction,
            'targetGroupLabels' => $targetGroupLabels,
            'defaultTargets' => $defaultTargets,
            'channelAvailability' => $channelAvailability,
            'canSendByAction' => $canSendByAction,
            'selectedTarget' => $defaultTargets[$selectedActionKey] ?? null,
            'selectedCanSend' => $canSendByAction[$selectedActionKey] ?? false,
            'selectedChannelAvailability' => $this->serializeChannelAvailability(
                $selectedDefinition,
                $selectedAvailability,
            ),
        ];
    }

    public function isValidTarget(CommunicationActionKey $key, string $targetValue, Incident $incident): bool
    {
        if ($targetValue === '') {
            return false;
        }

        $provider = $this->providerFor($key);

        return collect($provider->targets($incident))
            ->contains(fn ($target): bool => $target->value === $targetValue);
    }

    /**
     * @param  array<string, array<string, mixed>>  $availability
     * @return list<array{value: string, label: string, available: bool, reason: ?string}>
     */
    private function serializeChannelAvailability(
        CommunicationActionDefinition $definition,
        array $availability,
    ): array {
        return collect($definition->channels)
            ->map(function (NotificationChannelType $channel) use ($availability): array {
                $channelAvailability = $availability[$channel->value] ?? ['available' => false];

                return [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                    'available' => (bool) ($channelAvailability['available'] ?? false),
                    'reason' => $channelAvailability['reason'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
