<?php

namespace App\Services\ConversationWorkspace;

use App\Enums\ConversationQuestionKey;
use App\Models\ConversationWorkspaceSession;
use App\Models\Incident;
use App\Models\User;
use App\Services\CallCapture\CallCaptureQuestionResolver;
use Illuminate\Support\Carbon;

class ConversationWorkspaceSessionService
{
    public function __construct(
        private readonly CallCaptureQuestionResolver $questionResolver,
    ) {}

    public function firstOrCreateForIncident(
        Incident $incident,
        ?User $actor = null,
        ?string $callId = null,
    ): ConversationWorkspaceSession {
        $session = ConversationWorkspaceSession::query()
            ->firstOrCreate(
                ['incident_id' => $incident->id],
                [
                    'call_id' => $callId,
                    'customer_name' => $incident->order?->customer_name,
                    'status' => 'in_progress',
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ],
            );

        if ($callId !== null && $callId !== '' && $session->call_id !== $callId) {
            $session->forceFill([
                'call_id' => $callId,
                'updated_by' => $actor?->id,
            ])->save();
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        ConversationWorkspaceSession $session,
        array $attributes,
        ?User $actor = null,
    ): ConversationWorkspaceSession {
        $fillable = [
            'customer_name',
            'customer_need',
            'email',
            'whatsapp_same_number',
            'whatsapp_number',
            'brand',
            'model',
            'city',
            'source',
            'order_id_hint',
            'agent_notes',
            'disposition',
            'next_action',
            'current_step',
            'call_id',
        ];

        $payload = array_intersect_key($attributes, array_flip($fillable));

        if (array_key_exists('skipped_fields', $attributes) && is_array($attributes['skipped_fields'])) {
            $payload['skipped_fields'] = array_values(array_unique(array_merge(
                $session->skipped_fields ?? [],
                $attributes['skipped_fields'],
            )));
        }

        if (array_key_exists('completed_fields', $attributes) && is_array($attributes['completed_fields'])) {
            $payload['completed_fields'] = array_values(array_unique(array_merge(
                $session->completed_fields ?? [],
                $attributes['completed_fields'],
            )));
        }

        if (($attributes['mark_completed'] ?? false) === true) {
            $payload['status'] = 'completed';
            $payload['completed_at'] = Carbon::now();
        }

        $payload['updated_by'] = $actor?->id;

        $session->fill($payload)->save();

        $order = $session->incident?->order ?? $session->incident()->with('order')->first()?->order;

        if ($order !== null && $order->isInquiryOrder()) {
            $orderUpdates = [];

            if (array_key_exists('customer_name', $payload) && filled($payload['customer_name'])) {
                $orderUpdates['customer_name'] = $payload['customer_name'];
            }

            if (array_key_exists('email', $payload) && filled($payload['email'])) {
                $orderUpdates['customer_email'] = $payload['email'];
            }

            if ($orderUpdates !== []) {
                $orderUpdates['updated_by'] = $actor?->id;
                $order->forceFill($orderUpdates)->save();
            }
        }

        return $session->fresh();
    }

    /**
     * Lightweight view-model for the Conversation Workspace top section.
     *
     * @return array<string, mixed>
     */
    public function viewModel(ConversationWorkspaceSession $session): array
    {
        $captured = $session->capturedPayload();
        $skipped = $session->skipped_fields ?? [];

        foreach ($skipped as $field) {
            if (! array_key_exists($field, $captured) || $captured[$field] === null) {
                $captured[$field] = true;
            }
        }

        $activeQuestion = $this->resolveActiveQuestion($session, $captured);

        $checklist = [
            ConversationQuestionKey::CustomerName->value => filled($session->customer_name),
            ConversationQuestionKey::CustomerNeed->value => filled($session->customer_need),
            ConversationQuestionKey::Email->value => filled($session->email) || in_array('email', $skipped, true),
            ConversationQuestionKey::AgentNotes->value => filled($session->agent_notes),
            ConversationQuestionKey::Disposition->value => $session->disposition !== null,
            ConversationQuestionKey::NextAction->value => $session->next_action !== null,
        ];

        $done = count(array_filter($checklist));
        $total = count($checklist);

        return [
            'session_id' => $session->id,
            'call_id' => $session->call_id,
            'status' => $session->status,
            'captured' => $session->capturedPayload(),
            'skipped_fields' => $skipped,
            'active_question' => $activeQuestion?->toArray(),
            'checklist' => $checklist,
            'progress' => [
                'done' => $done,
                'total' => $total,
                'label' => "{$done} / {$total}",
            ],
            'mandatory_complete' => $session->hasMandatoryLiveFields(),
            'dispositions' => config('conversation_workspace.dispositions', []),
            'next_actions' => config('conversation_workspace.next_actions', []),
            'ira_tip' => 'First contact. Understand the need. Capture enough so the next agent never starts from zero.',
        ];
    }

    /**
     * @param  array<string, mixed>  $captured
     */
    private function resolveActiveQuestion(
        ConversationWorkspaceSession $session,
        array $captured,
    ): ?\App\Data\ConversationWorkspace\ConversationQuestion {
        if (! filled($session->customer_name)) {
            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::CustomerName,
                prompt: "What's their name?",
                inputType: 'text',
                required: true,
                skippable: false,
            );
        }

        if (! filled($session->customer_need)) {
            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::CustomerNeed,
                prompt: 'What do they need?',
                inputType: 'textarea',
                required: true,
                skippable: false,
            );
        }

        $followUp = $this->questionResolver->nextQuestion(
            (string) $session->customer_need,
            $session->current_step,
            $captured,
        );

        if ($followUp !== null) {
            return $followUp;
        }

        $skipped = $session->skipped_fields ?? [];

        if (! filled($session->email) && ! in_array('email', $skipped, true)) {
            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::Email,
                prompt: 'Email?',
                inputType: 'email',
                required: false,
                skippable: true,
            );
        }

        if ($session->whatsapp_same_number === null && ! in_array('whatsapp', $skipped, true)) {
            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::Whatsapp,
                prompt: 'WhatsApp on this number?',
                inputType: 'choice',
                required: false,
                skippable: true,
                options: [
                    ['value' => 'same', 'label' => 'Yes'],
                    ['value' => 'different', 'label' => 'Different'],
                    ['value' => 'skip', 'label' => 'Skip'],
                ],
            );
        }

        if (! filled($session->agent_notes) && ! in_array('agent_notes', $skipped, true)) {
            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::AgentNotes,
                prompt: 'Agent notes',
                inputType: 'textarea',
                required: false,
                skippable: true,
                hint: 'Natural notes. IRA will summarize later.',
            );
        }

        if ($session->disposition === null && ! in_array('disposition', $skipped, true)) {
            $options = [];
            foreach (config('conversation_workspace.dispositions', []) as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => (string) $label];
            }

            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::Disposition,
                prompt: 'Disposition',
                inputType: 'select',
                required: false,
                skippable: true,
                options: $options,
            );
        }

        if ($session->next_action === null && ! in_array('next_action', $skipped, true)) {
            $options = [];
            foreach (config('conversation_workspace.next_actions', []) as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => (string) $label];
            }

            return new \App\Data\ConversationWorkspace\ConversationQuestion(
                key: ConversationQuestionKey::NextAction,
                prompt: 'Next action',
                inputType: 'select',
                required: false,
                skippable: true,
                options: $options,
            );
        }

        return null;
    }
}
