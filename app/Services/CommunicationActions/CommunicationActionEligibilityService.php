<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionKey;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Services\CommunicationActions\BuyProduct\BuyProductEligibilityService;
use App\Services\CommunicationActions\BuyRdService\BuyRdServiceEligibilityService;
use App\Services\CommunicationActions\DriverInstallationGuide\DriverInstallationGuideEligibilityService;
use App\Services\CommunicationActions\RefundConfirmation\RefundConfirmationEligibilityService;
use App\Services\CommunicationActions\ReviewRequest\ReviewRequestEligibilityService;

class CommunicationActionEligibilityService
{
    public function __construct(
        private readonly CommunicationActionRegistry $registry,
        private readonly DriverInstallationGuideEligibilityService $driverInstallationGuideEligibilityService,
        private readonly ReviewRequestEligibilityService $reviewRequestEligibilityService,
        private readonly RefundConfirmationEligibilityService $refundConfirmationEligibilityService,
        private readonly BuyRdServiceEligibilityService $buyRdServiceEligibilityService,
        private readonly BuyProductEligibilityService $buyProductEligibilityService,
    ) {}
    public function canShowAction(
        CommunicationActionDefinition $definition,
        Incident $incident,
        ?User $user,
    ): bool {
        return $this->ineligibilityReason($definition, $incident, $user) === null;
    }

    public function ineligibilityReason(
        CommunicationActionDefinition $definition,
        Incident $incident,
        ?User $user,
    ): ?string {
        if ($user === null) {
            return 'You must be signed in to use this action.';
        }

        if ($definition->executionMode !== CommunicationActionExecutionMode::Manual) {
            return 'This action is not available for manual execution.';
        }

        if ($definition->allowedRoles !== [] && ! $user->hasAnyRole($definition->allowedRoles)) {
            return 'You do not have permission to run this communication action.';
        }

        if ($incident->status === IncidentStatus::Closed
            && ! in_array($definition->key, [
                CommunicationActionKey::ReviewRequest,
                CommunicationActionKey::RefundConfirmation,
            ], true)) {
            return 'Communication actions are unavailable on closed service cases.';
        }

        $incident->loadMissing('order');

        if ($incident->order === null) {
            return 'Link an order before sending communication actions.';
        }

        return $this->actionSpecificIneligibilityReason($definition, $incident, $user);
    }

    private function actionSpecificIneligibilityReason(
        CommunicationActionDefinition $definition,
        Incident $incident,
        ?User $user,
    ): ?string {
        return match ($definition->key) {
            CommunicationActionKey::DriverInstallationGuide => $this->driverInstallationGuideEligibilityService
                ->ineligibilityReason($incident),
            CommunicationActionKey::ReviewRequest => $this->reviewRequestEligibilityService
                ->ineligibilityReason($incident),
            CommunicationActionKey::RefundConfirmation => $this->refundConfirmationEligibilityService
                ->ineligibilityReason($incident),
            CommunicationActionKey::BuyRdService => $this->buyRdServiceEligibilityService
                ->ineligibilityReason($incident),
            CommunicationActionKey::BuyProduct => $this->buyProductEligibilityService
                ->ineligibilityReason($incident),
            default => null,
        };
    }

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     icon: string,
     *     channels: list<array{value: string, label: string}>,
     *     eligible: bool,
     *     disabled_reason: string|null,
     * }>
     */
    public function menuItems(Incident $incident, ?User $user): array
    {
        return $this->registry
            ->all()
            ->map(function (CommunicationActionDefinition $definition) use ($incident, $user): array {
                $reason = $this->ineligibilityReason($definition, $incident, $user);

                return [
                    'key' => $definition->key->value,
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'icon' => $definition->icon,
                    'channels' => collect($definition->channels)
                        ->map(fn ($channel): array => [
                            'value' => $channel->value,
                            'label' => $channel->label(),
                        ])
                        ->values()
                        ->all(),
                    'eligible' => $reason === null,
                    'disabled_reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }
}
