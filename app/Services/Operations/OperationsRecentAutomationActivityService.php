<?php

namespace App\Services\Operations;

use App\Models\AutomationExecution;
use App\Models\Incident;
use Illuminate\Support\Facades\Schema;

class OperationsRecentAutomationActivityService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return [];
        }

        return AutomationExecution::query()
            ->with(['waitingState.incident.order'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationExecution $execution): array => $this->mapExecution($execution))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapExecution(AutomationExecution $execution): array
    {
        $incident = $execution->waitingState?->incident;
        $durationMs = null;

        if ($execution->started_at !== null && $execution->completed_at !== null) {
            $durationMs = $execution->started_at->diffInMilliseconds($execution->completed_at);
        }

        return [
            'timestamp' => $execution->created_at,
            'trigger' => $this->triggerLabel($execution),
            'result' => $execution->status->value,
            'result_label' => ucfirst($execution->status->value),
            'duration_ms' => $durationMs,
            'channels' => $this->channelsUsed($execution),
            'incident_reference' => $incident instanceof Incident ? $incident->display_reference : null,
            'incident_url' => $incident instanceof Incident ? route('incidents.show', $incident) : null,
            'error_message' => $execution->error_message,
        ];
    }

    private function triggerLabel(AutomationExecution $execution): string
    {
        $actionKey = $execution->action_key;
        $policyKey = $execution->policy_key;
        $step = $execution->schedule_step;

        return "{$policyKey} · step {$step} · {$actionKey}";
    }

    /**
     * @return list<string>
     */
    private function channelsUsed(AutomationExecution $execution): array
    {
        if (filled($execution->channel)) {
            return [$this->channelLabel((string) $execution->channel)];
        }

        $channelResults = $execution->metadata['channel_results'] ?? [];

        if (! is_array($channelResults)) {
            return [];
        }

        $channels = [];

        foreach ($channelResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            $channel = (string) ($result['channel'] ?? '');

            if ($channel !== '') {
                $channels[] = $this->channelLabel($channel);
            }
        }

        return array_values(array_unique($channels));
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'desktop' => 'Desktop',
            'telegram' => 'Telegram',
            default => ucfirst($channel),
        };
    }
}
