<?php

namespace App\Support\Operations;

use App\Models\AutomationExecution;

class AutomationExecutionClassifier
{
    public const TYPE_WAITING_LIFECYCLE = 'waiting_lifecycle';

    public const TYPE_APPOINTMENT_REMINDER = 'appointment_reminder';

    public const TYPE_COMMUNICATION_ACTION = 'communication_action';

    public const TYPE_FUTURE_AI = 'future_ai';

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return [
            self::TYPE_WAITING_LIFECYCLE,
            self::TYPE_APPOINTMENT_REMINDER,
            self::TYPE_COMMUNICATION_ACTION,
            self::TYPE_FUTURE_AI,
        ];
    }

    public function typeFor(AutomationExecution $execution): string
    {
        if ($execution->policy_key === 'appointment-reminder') {
            return self::TYPE_APPOINTMENT_REMINDER;
        }

        if ($execution->waiting_state_id !== null) {
            return self::TYPE_WAITING_LIFECYCLE;
        }

        if (is_string($execution->policy_key) && str_starts_with($execution->policy_key, 'communication')) {
            return self::TYPE_COMMUNICATION_ACTION;
        }

        return self::TYPE_FUTURE_AI;
    }

    public function label(string $type): string
    {
        return match ($type) {
            self::TYPE_WAITING_LIFECYCLE => 'Waiting Lifecycle',
            self::TYPE_APPOINTMENT_REMINDER => 'Appointment Reminder',
            self::TYPE_COMMUNICATION_ACTION => 'Communication Action',
            self::TYPE_FUTURE_AI => 'Future AI Automation',
            default => 'Automation',
        };
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        $options = [];

        foreach ($this->types() as $type) {
            $options[$type] = $this->label($type);
        }

        return $options;
    }
}
