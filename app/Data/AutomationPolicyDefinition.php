<?php

namespace App\Data;

use App\Enums\AutomationPolicyActionType;
use App\Exceptions\InvalidAutomationPolicyException;

readonly class AutomationPolicyDefinition
{
    /**
     * @param  list<AutomationPolicyScheduleEntry>  $schedule
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $schedule,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $label = $config['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            throw InvalidAutomationPolicyException::forKey($key, 'label must be a non-empty string.');
        }

        $scheduleConfig = $config['schedule'] ?? null;
        if (! is_array($scheduleConfig) || $scheduleConfig === []) {
            throw InvalidAutomationPolicyException::forKey($key, 'schedule must be a non-empty array.');
        }

        $schedule = [];
        $seenDays = [];

        foreach ($scheduleConfig as $index => $entryConfig) {
            if (! is_array($entryConfig)) {
                throw InvalidAutomationPolicyException::forKey($key, "schedule[{$index}] must be an array.");
            }

            $day = $entryConfig['day'] ?? null;
            if (! is_int($day) || $day < 0) {
                throw InvalidAutomationPolicyException::forKey($key, "schedule[{$index}].day must be a non-negative integer.");
            }

            if (array_key_exists($day, $seenDays)) {
                throw InvalidAutomationPolicyException::forKey($key, "schedule contains duplicate day [{$day}].");
            }
            $seenDays[$day] = true;

            $actionsConfig = $entryConfig['actions'] ?? null;
            if (! is_array($actionsConfig) || $actionsConfig === []) {
                throw InvalidAutomationPolicyException::forKey($key, "schedule[{$index}].actions must be a non-empty array.");
            }

            $actions = [];
            foreach ($actionsConfig as $actionIndex => $actionConfig) {
                $actions[] = self::parseAction($key, $index, $actionIndex, $actionConfig);
            }

            $schedule[] = new AutomationPolicyScheduleEntry(
                day: $day,
                actions: $actions,
            );
        }

        usort(
            $schedule,
            fn (AutomationPolicyScheduleEntry $left, AutomationPolicyScheduleEntry $right): int => $left->day <=> $right->day,
        );

        return new self(
            key: $key,
            label: $label,
            schedule: $schedule,
        );
    }

    /**
     * @param  array<string, mixed>  $actionConfig
     */
    private static function parseAction(string $policyKey, int $entryIndex, int $actionIndex, array $actionConfig): AutomationPolicyAction
    {
        $typeValue = $actionConfig['type'] ?? null;
        if (! is_string($typeValue) || trim($typeValue) === '') {
            throw InvalidAutomationPolicyException::forKey(
                $policyKey,
                "schedule[{$entryIndex}].actions[{$actionIndex}].type must be a non-empty string.",
            );
        }

        $type = AutomationPolicyActionType::tryFromConfig($typeValue);
        if ($type === null) {
            throw InvalidAutomationPolicyException::forKey(
                $policyKey,
                "schedule[{$entryIndex}].actions[{$actionIndex}].type [{$typeValue}] is not supported.",
            );
        }

        $actionKey = $actionConfig['key'] ?? null;
        if (! is_string($actionKey) || trim($actionKey) === '') {
            throw InvalidAutomationPolicyException::forKey(
                $policyKey,
                "schedule[{$entryIndex}].actions[{$actionIndex}].key must be a non-empty string.",
            );
        }

        $config = $actionConfig['config'] ?? [];
        if (! is_array($config)) {
            throw InvalidAutomationPolicyException::forKey(
                $policyKey,
                "schedule[{$entryIndex}].actions[{$actionIndex}].config must be an array when provided.",
            );
        }

        if ($type !== AutomationPolicyActionType::Custom && $config !== []) {
            throw InvalidAutomationPolicyException::forKey(
                $policyKey,
                "schedule[{$entryIndex}].actions[{$actionIndex}].config is only allowed for custom actions.",
            );
        }

        return new AutomationPolicyAction(
            type: $type,
            key: $actionKey,
            config: $config,
        );
    }
}
