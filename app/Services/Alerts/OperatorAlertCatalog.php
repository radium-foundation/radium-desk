<?php

namespace App\Services\Alerts;

use App\Data\OperatorAlert;
use App\Enums\AlertSeverity;
use App\Enums\NotificationCategory;
use InvalidArgumentException;

class OperatorAlertCatalog
{
    public const EVENT_INCOMING_CALL = 'incoming_call';

    public const EVENT_HIGH_PRIORITY_SERVICE_CASE = 'high_priority_service_case';

    public const EVENT_SERVICE_CASE_ASSIGNED = 'service_case_assigned';

    public const EVENT_SERVICE_CASE_REASSIGNED = 'service_case_reassigned';

    public const EVENT_SERVICE_CASE_CUSTOMER_RESPONDED = 'service_case_customer_responded';

    public const EVENT_TRANSACTION_COMPLETED = 'transaction_completed';

    public const EVENT_SMART_ASSIGNMENT_UNASSIGNED = 'smart_assignment_unassigned';

    public const EVENT_LEAVE_REQUEST_SUBMITTED = 'leave_request_submitted';

    public const EVENT_LEAVE_REQUEST_DECISION = 'leave_request_decision';

    public const EVENT_REFUND_REQUEST_SUBMITTED = 'refund_request_submitted';

    public const EVENT_REFUND_REQUEST_DECISION = 'refund_request_decision';

    /**
     * @return array{
     *     severity: AlertSeverity,
     *     category: NotificationCategory,
     *     icon: string,
     *     desktop_popup: bool,
     *     play_sound: bool
     * }
     */
    public function definitionFor(string $eventType): array
    {
        return match ($eventType) {
            self::EVENT_INCOMING_CALL => [
                'severity' => AlertSeverity::Critical,
                'category' => NotificationCategory::Ivr,
                'icon' => 'bi-telephone-inbound',
                'desktop_popup' => true,
                'play_sound' => true,
            ],
            self::EVENT_HIGH_PRIORITY_SERVICE_CASE => [
                'severity' => AlertSeverity::High,
                'category' => NotificationCategory::Escalation,
                'icon' => 'bi-exclamation-triangle',
                'desktop_popup' => true,
                'play_sound' => true,
            ],
            self::EVENT_SERVICE_CASE_CUSTOMER_RESPONDED => [
                'severity' => AlertSeverity::High,
                'category' => NotificationCategory::Assignment,
                'icon' => 'bi-chat-dots',
                'desktop_popup' => true,
                'play_sound' => true,
            ],
            self::EVENT_SMART_ASSIGNMENT_UNASSIGNED => [
                'severity' => AlertSeverity::High,
                'category' => NotificationCategory::Escalation,
                'icon' => 'bi-person-x',
                'desktop_popup' => true,
                'play_sound' => true,
            ],
            self::EVENT_SERVICE_CASE_ASSIGNED,
            self::EVENT_SERVICE_CASE_REASSIGNED => [
                'severity' => AlertSeverity::Medium,
                'category' => NotificationCategory::Assignment,
                'icon' => 'bi-person-check',
                'desktop_popup' => true,
                'play_sound' => false,
            ],
            self::EVENT_TRANSACTION_COMPLETED => [
                'severity' => AlertSeverity::Medium,
                'category' => NotificationCategory::Finance,
                'icon' => 'bi-cash-coin',
                'desktop_popup' => true,
                'play_sound' => false,
            ],
            self::EVENT_LEAVE_REQUEST_SUBMITTED,
            self::EVENT_LEAVE_REQUEST_DECISION => [
                'severity' => AlertSeverity::Medium,
                'category' => NotificationCategory::LeaveApprovals,
                'icon' => 'bi-calendar-event',
                'desktop_popup' => true,
                'play_sound' => false,
            ],
            self::EVENT_REFUND_REQUEST_SUBMITTED,
            self::EVENT_REFUND_REQUEST_DECISION => [
                'severity' => AlertSeverity::Medium,
                'category' => NotificationCategory::Finance,
                'icon' => 'bi-receipt',
                'desktop_popup' => true,
                'play_sound' => false,
            ],
            default => throw new InvalidArgumentException("Unknown operator alert event type [{$eventType}]."),
        };
    }

    /**
     * @param  array<string, mixed>|null  $interaction
     */
    public function make(
        string $eventType,
        string $title,
        string $message,
        string $actionUrl,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $deduplicationKey = null,
        ?array $interaction = null,
    ): OperatorAlert {
        $definition = $this->definitionFor($eventType);

        return new OperatorAlert(
            title: $title,
            message: $message,
            severity: $definition['severity'],
            category: $definition['category'],
            icon: $definition['icon'],
            actionUrl: $actionUrl,
            entityType: $entityType,
            entityId: $entityId,
            deduplicationKey: $deduplicationKey ?? $this->defaultDeduplicationKey($eventType, $entityType, $entityId),
            interaction: $interaction,
            desktopPopup: $definition['desktop_popup'],
            playSound: $definition['play_sound'],
        );
    }

    public function supports(string $eventType): bool
    {
        try {
            $this->definitionFor($eventType);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function defaultDeduplicationKey(
        string $eventType,
        ?string $entityType,
        int|string|null $entityId,
    ): string {
        if ($entityType !== null && $entityId !== null) {
            return "{$eventType}:{$entityType}:{$entityId}";
        }

        return $eventType;
    }
}
