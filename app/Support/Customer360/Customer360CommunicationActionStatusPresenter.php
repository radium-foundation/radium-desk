<?php

namespace App\Support\Customer360;

use App\Enums\CommunicationActionLifecycleStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Support\AppDateFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class Customer360CommunicationActionStatusPresenter
{
    public function __construct(
        private readonly CommunicationActionRegistry $registry,
        private readonly CommunicationActionEligibilityService $eligibilityService,
        private readonly CommunicationActionLifecycleService $lifecycleService,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     display_name: string,
     *     icon: string,
     *     icon_class: string,
     *     eligible: bool,
     *     status: string,
     *     status_label: string,
     *     status_variant: string,
     *     status_icon: string|null,
     *     show_already_sent: bool,
     *     already_sent_label: string|null,
     *     helper_text: string|null,
     *     clickable: bool,
     *     show_chevron: bool,
     * }>
     */
    public function forIncident(Incident $incident, ?User $user): array
    {
        return $this->registry
            ->all()
            ->map(fn ($definition): array => $this->presentAction(
                incident: $incident,
                user: $user,
                actionKey: $definition->key->value,
                name: $definition->name,
                icon: $definition->icon,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     display_name: string,
     *     icon: string,
     *     icon_class: string,
     *     eligible: bool,
     *     status: string,
     *     status_label: string,
     *     status_variant: string,
     *     status_icon: string|null,
     *     show_already_sent: bool,
     *     already_sent_label: string|null,
     *     helper_text: string|null,
     *     clickable: bool,
     *     show_chevron: bool,
     * }
     */
    private function presentAction(
        Incident $incident,
        ?User $user,
        string $actionKey,
        string $name,
        string $icon,
    ): array {
        $definition = $this->registry->get($actionKey);
        $ineligibilityReason = $this->eligibilityService->ineligibilityReason($definition, $incident, $user);
        $eligible = $ineligibilityReason === null;
        $lifecycleStatus = $this->lifecycleService->resolveStatus($incident, $actionKey, $user);
        $lastSentEvent = $this->lastSentLifecycleEvent($incident, $actionKey);
        $latestEvent = $this->lifecycleService->latestLifecycleEvent($incident, $actionKey);
        $latestStatus = $this->auditLifecycleStatus($latestEvent);

        if (! $eligible) {
            return $this->actionPayload(
                actionKey: $actionKey,
                name: $name,
                icon: $icon,
                eligible: false,
                status: 'not_eligible',
                statusLabel: $ineligibilityReason ?? 'Not Eligible',
                statusVariant: 'muted',
                statusIcon: null,
                showAlreadySent: false,
            );
        }

        if ($latestStatus === CommunicationActionLifecycleStatus::Skipped) {
            return $this->actionPayload(
                actionKey: $actionKey,
                name: $name,
                icon: $icon,
                eligible: true,
                status: 'skipped',
                statusLabel: 'Skipped',
                statusVariant: 'warning',
                statusIcon: null,
                showAlreadySent: false,
            );
        }

        if ($lastSentEvent !== null) {
            $sentLabel = $this->formatSentLabel($lastSentEvent->created_at);

            return $this->actionPayload(
                actionKey: $actionKey,
                name: $name,
                icon: $icon,
                eligible: true,
                status: 'sent',
                statusLabel: $sentLabel,
                statusVariant: 'success',
                statusIcon: '✓',
                showAlreadySent: $lifecycleStatus === CommunicationActionLifecycleStatus::Sent,
            );
        }

        return $this->actionPayload(
            actionKey: $actionKey,
            name: $name,
            icon: $icon,
            eligible: true,
            status: 'available',
            statusLabel: 'Available',
            statusVariant: 'info',
            statusIcon: null,
            showAlreadySent: false,
        );
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     display_name: string,
     *     icon: string,
     *     icon_class: string,
     *     eligible: bool,
     *     status: string,
     *     status_label: string,
     *     status_variant: string,
     *     status_icon: string|null,
     *     show_already_sent: bool,
     *     already_sent_label: string|null,
     *     helper_text: string|null,
     *     clickable: bool,
     *     show_chevron: bool,
     * }
     */
    private function actionPayload(
        string $actionKey,
        string $name,
        string $icon,
        bool $eligible,
        string $status,
        string $statusLabel,
        string $statusVariant,
        ?string $statusIcon,
        bool $showAlreadySent,
    ): array {
        $clickable = $eligible && $status !== 'skipped';
        $rawHelperText = match (true) {
            ! $eligible => $statusLabel,
            $status === 'sent', $status === 'skipped' => $statusLabel,
            default => null,
        };
        $helperText = $rawHelperText !== null
            ? Customer360CommunicationActionHelperTextPresenter::for($rawHelperText, $status)
            : null;

        return [
            'key' => $actionKey,
            'name' => $name,
            'display_name' => Customer360CommunicationActionDisplayName::for($actionKey, $name),
            'icon' => Customer360OverflowMenuLucideIcon::resolve($icon),
            'icon_class' => str_starts_with($icon, 'bi-') ? $icon : 'bi-circle',
            'eligible' => $eligible,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_variant' => $statusVariant,
            'status_icon' => $statusIcon,
            'show_already_sent' => $showAlreadySent,
            'already_sent_label' => $showAlreadySent ? 'Already Sent' : null,
            'helper_text' => $helperText,
            'clickable' => $clickable,
            'show_chevron' => $clickable && $status === 'available',
        ];
    }

    private function lastSentLifecycleEvent(Incident $incident, string $actionKey): ?AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->where('new_values->action_key', $actionKey)
            ->where('new_values->status', CommunicationActionLifecycleStatus::Sent->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function auditLifecycleStatus(?AuditLog $auditLog): ?CommunicationActionLifecycleStatus
    {
        if ($auditLog === null) {
            return null;
        }

        return CommunicationActionLifecycleStatus::tryFrom(
            (string) ($auditLog->new_values['status'] ?? ''),
        );
    }

    private function formatSentLabel(?CarbonInterface $sentAt): string
    {
        $localized = AppDateFormatter::inAppTimezone($sentAt);

        if ($localized === null) {
            return 'Sent';
        }

        if ($localized->isToday()) {
            return 'Sent today';
        }

        if ($localized->isYesterday()) {
            return 'Sent yesterday';
        }

        $relative = $localized->diffForHumans(now(AppDateFormatter::timezone()), [
            'syntax' => Carbon::DIFF_ABSOLUTE,
            'parts' => 1,
            'short' => false,
        ]);

        return 'Sent '.$relative;
    }
}
