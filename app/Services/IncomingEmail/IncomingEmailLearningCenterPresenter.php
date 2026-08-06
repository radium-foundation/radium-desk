<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Operator-facing row payloads for the IRA Learning Center.
 * Never exposes internal statuses like needs_review / unknown_customer.
 */
class IncomingEmailLearningCenterPresenter
{
    public function __construct(
        private readonly IncomingEmailLearningRulesService $learningRulesService,
    ) {}

    /**
     * @param  Collection<int, IncomingEmailMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function cardsFor(Collection $messages, ?IncomingEmailIntakeQueue $queue = null): array
    {
        $messages->loadMissing([
            'order:id,customer_name,customer_email',
            'incident:id,reference_no,status',
            'learningOwner:id,name,first_name,last_name',
            'suggestedAssignee:id,name,first_name,last_name',
            'matchedLearningRule.creator:id,name,first_name,last_name',
            'disposedBy:id,name,first_name,last_name',
        ]);

        return $messages
            ->map(fn (IncomingEmailMessage $message): array => $this->cardFor($message, $queue))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function cardFor(IncomingEmailMessage $message, ?IncomingEmailIntakeQueue $queue = null): array
    {
        $suggestion = $this->buildSuggestion($message);
        $fullPreview = $this->fullPreview($message->displayPreview());
        $confidence = (int) $suggestion['confidence'];
        $confidenceBand = $this->confidenceBand($confidence);
        $rule = $message->matchedLearningRule;
        $subject = filled($message->subject) ? (string) $message->subject : 'No subject';
        $isCompletedAutomatically = $queue === IncomingEmailIntakeQueue::Automatic;
        $resultLabel = $this->completedAutomaticallyResult($message);

        return [
            'id' => $message->id,
            'queue' => $queue?->value,
            'is_completed_automatically' => $isCompletedAutomatically,
            'is_spam_queue' => $queue === IncomingEmailIntakeQueue::Spam
                || (
                    $message->status === IncomingEmailMessageStatus::Ignored
                    && (
                        $message->classification === IncomingEmailClassification::Spam
                        || in_array((string) $message->ignore_reason, ['spam', 'trash'], true)
                    )
                ),
            'sender' => $this->senderLabel($message),
            'sender_email' => $message->from_email,
            'customer' => $this->customerLabel($message),
            'subject' => $subject,
            'preview' => Str::limit($fullPreview, 90),
            'preview_full' => $fullPreview,
            'received_at' => $message->received_at,
            'received_label' => $message->received_at
                ? display_app_datetime($message->received_at)
                : '—',
            'ira_decision' => $isCompletedAutomatically
                ? $resultLabel
                : $suggestion['decision'],
            'handled_by' => $isCompletedAutomatically ? 'IRA' : null,
            'result_label' => $isCompletedAutomatically ? $resultLabel : null,
            'confidence' => $confidence,
            'confidence_band' => $confidenceBand,
            'confidence_label' => $confidenceBand,
            'confidence_percent' => $confidence.'%',
            'suggested_assignee' => $suggestion['suggested_assignee'],
            'suggested_assignee_user_id' => $suggestion['suggested_assignee_user_id'],
            'reason' => $suggestion['reason'],
            'importance' => ($message->importance ?? IncomingEmailImportance::Normal)->label(),
            'importance_value' => ($message->importance ?? IncomingEmailImportance::Normal)->value,
            'classification_label' => IncomingEmailOperatorClassification::fromStored($message->classification)?->label(),
            'explanation' => $suggestion['explanation'],
            'learning_owner' => $this->userLabel($message->learningOwner),
            'service_case' => $this->serviceCaseLabel($message),
            'matched_learning_rule' => $this->matchedRuleLabel($message),
            'previous_confirmations' => $this->previousConfirmationsLabel($suggestion['explanation'], $rule?->times_used),
            'gmail_url' => $this->gmailUrl($message),
            'customer_360_url' => $message->incident_id
                ? route('dashboard.service-cases.customer-360', $message->incident_id)
                : null,
            'keep_pending' => $message->disposition === IncomingEmailDisposition::KeepPending,
            'keep_pending_label' => $this->keepPendingLabel($message),
            'expand' => [
                'subject' => $subject,
                'preview' => $fullPreview,
                'why' => $suggestion['reason'],
                'customer_label' => $this->customerLabel($message),
                'service_case' => $this->serviceCaseLabel($message),
                'matched_learning_rule' => $this->matchedRuleLabel($message),
                'previous_confirmations' => $this->previousConfirmationsLabel(
                    $suggestion['explanation'],
                    $rule?->times_used,
                ),
                'explanation' => $suggestion['explanation'],
                'disposition' => $message->disposition?->label(),
                'keep_pending_reason' => $this->keepPendingLabel($message),
            ],
        ];
    }

    private function completedAutomaticallyResult(IncomingEmailMessage $message): string
    {
        $incident = $message->incident;

        if ($incident !== null) {
            $number = filled($incident->reference_no)
                ? (string) $incident->reference_no
                : 'SC'.$incident->id;

            return 'Linked to '.$number;
        }

        return 'Completed Automatically';
    }

    private function keepPendingLabel(IncomingEmailMessage $message): ?string
    {
        if ($message->disposition !== IncomingEmailDisposition::KeepPending) {
            return null;
        }

        $reason = IncomingEmailKeepPendingReason::tryFrom((string) $message->disposition_reason);

        return $reason?->label() ?? 'Kept pending';
    }

    private function confidenceBand(int $confidence): string
    {
        if ($confidence >= 75) {
            return 'High';
        }

        if ($confidence >= 45) {
            return 'Medium';
        }

        return 'Low';
    }

    /**
     * @return array{
     *     decision: string,
     *     confidence: int,
     *     suggested_assignee: string,
     *     suggested_assignee_user_id: int|null,
     *     reason: string,
     *     explanation: array{
     *         why: string,
     *         examples: list<string>,
     *         matched_sender: ?string,
     *         matched_keyword: ?string,
     *         previous_operator_confirmation: bool,
     *         rule_confidence: ?int
     *     }
     * }
     */
    private function buildSuggestion(IncomingEmailMessage $message): array
    {
        if (filled($message->ira_decision)) {
            $explanation = is_array($message->ira_explanation) ? $message->ira_explanation : [];

            return [
                'decision' => (string) $message->ira_decision,
                'confidence' => (int) ($message->ira_confidence ?? 50),
                'suggested_assignee' => $this->userLabel($message->suggestedAssignee)
                    ?? $this->userLabel($message->learningOwner)
                    ?? 'Unassigned',
                'suggested_assignee_user_id' => $message->suggested_assignee_user_id
                    ?? $message->learning_owner_user_id,
                'reason' => (string) ($message->ira_reason ?: 'Waiting for operator review.'),
                'explanation' => $this->normalizeExplanation($explanation, $message),
            ];
        }

        $matches = $this->learningRulesService->matchesFor($message);
        $assignMatch = collect($matches)->first(
            static fn ($match) => $match->rule->decision_type->value === 'assign',
        );

        if ($assignMatch !== null) {
            $assignee = User::query()->find((int) $assignMatch->rule->decision_value);

            return [
                'decision' => 'Assign to teammate',
                'confidence' => (int) $assignMatch->rule->confidence,
                'suggested_assignee' => $this->userLabel($assignee) ?? 'Unassigned',
                'suggested_assignee_user_id' => $assignee?->id,
                'reason' => 'Matches learning rule for '.$assignMatch->matchedOn.'.',
                'explanation' => [
                    'why' => 'An operator-confirmed learning rule matches this email.',
                    'examples' => [
                        'Matched '.$assignMatch->matchedOn.': '.$assignMatch->matchedValue,
                        'Times used: '.$assignMatch->rule->times_used,
                    ],
                    'matched_sender' => $message->from_email,
                    'matched_keyword' => $assignMatch->rule->rule_type->value === 'keyword'
                        ? $assignMatch->matchedValue
                        : null,
                    'previous_operator_confirmation' => true,
                    'rule_confidence' => $assignMatch->rule->confidence,
                ],
            ];
        }

        $operatorClass = IncomingEmailOperatorClassification::fromStored($message->classification);
        $decision = match (true) {
            $message->status === IncomingEmailMessageStatus::Failed => 'Needs recovery',
            $operatorClass === IncomingEmailOperatorClassification::Sales => 'Possible sales enquiry',
            $operatorClass === IncomingEmailOperatorClassification::Refund => 'Possible refund enquiry',
            $operatorClass === IncomingEmailOperatorClassification::Support => 'Support enquiry',
            $operatorClass === IncomingEmailOperatorClassification::Vendor => 'Vendor / ops mail',
            $operatorClass === IncomingEmailOperatorClassification::Docs => 'Docs',
            $operatorClass === IncomingEmailOperatorClassification::Promotion => 'Promotion',
            $operatorClass === IncomingEmailOperatorClassification::Spam => 'Spam',
            $operatorClass === IncomingEmailOperatorClassification::Automatic => 'Completed Automatically',
            $message->order_id !== null => 'Customer email needs routing',
            default => 'Needs operator decision',
        };

        $reason = match (true) {
            $message->status === IncomingEmailMessageStatus::Failed => 'Automatic processing could not finish.',
            $message->order_id !== null => 'Matched a customer order but needs human routing.',
            $operatorClass === IncomingEmailOperatorClassification::Sales => 'Looks like a new purchase enquiry.',
            default => 'No confident automatic route was available.',
        };

        $confidence = match (true) {
            $message->status === IncomingEmailMessageStatus::Failed => 20,
            $message->order_id !== null => 70,
            $operatorClass !== null => 55,
            default => 35,
        };

        return [
            'decision' => $decision,
            'confidence' => $confidence,
            'suggested_assignee' => $this->userLabel($message->suggestedAssignee)
                ?? $this->userLabel($message->learningOwner)
                ?? 'Unassigned',
            'suggested_assignee_user_id' => $message->suggested_assignee_user_id
                ?? $message->learning_owner_user_id,
            'reason' => $reason,
            'explanation' => [
                'why' => $reason,
                'examples' => array_values(array_filter([
                    $message->from_email ? 'Sender: '.$message->from_email : null,
                    $operatorClass ? 'Suggested class: '.$operatorClass->label() : null,
                    $message->mailbox ? 'Mailbox: '.$message->mailbox : null,
                ])),
                'matched_sender' => $message->from_email,
                'matched_keyword' => null,
                'previous_operator_confirmation' => false,
                'rule_confidence' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $explanation
     * @return array{
     *     why: string,
     *     examples: list<string>,
     *     matched_sender: ?string,
     *     matched_keyword: ?string,
     *     previous_operator_confirmation: bool,
     *     rule_confidence: ?int
     * }
     */
    private function normalizeExplanation(array $explanation, IncomingEmailMessage $message): array
    {
        $examples = $explanation['examples'] ?? [];

        if (! is_array($examples)) {
            $examples = [];
        }

        return [
            'why' => (string) ($explanation['why'] ?? $message->ira_reason ?? 'Waiting for operator review.'),
            'examples' => array_values(array_map('strval', $examples)),
            'matched_sender' => isset($explanation['matched_sender'])
                ? (string) $explanation['matched_sender']
                : $message->from_email,
            'matched_keyword' => isset($explanation['matched_keyword']) && $explanation['matched_keyword'] !== null
                ? (string) $explanation['matched_keyword']
                : null,
            'previous_operator_confirmation' => (bool) ($explanation['previous_operator_confirmation'] ?? false),
            'rule_confidence' => isset($explanation['rule_confidence'])
                ? (int) $explanation['rule_confidence']
                : ($message->ira_confidence !== null ? (int) $message->ira_confidence : null),
        ];
    }

    private function senderLabel(IncomingEmailMessage $message): string
    {
        if (filled($message->from_name)) {
            return (string) $message->from_name;
        }

        return filled($message->from_email) ? (string) $message->from_email : 'Unknown sender';
    }

    private function customerLabel(IncomingEmailMessage $message): string
    {
        $order = $message->order;

        if ($order !== null && filled($order->customer_name)) {
            return (string) $order->customer_name;
        }

        if ($order !== null && filled($order->customer_email)) {
            return (string) $order->customer_email;
        }

        return 'Unknown Customer';
    }

    private function serviceCaseLabel(IncomingEmailMessage $message): string
    {
        $incident = $message->incident;

        if ($incident === null) {
            return 'No service case';
        }

        $number = filled($incident->reference_no)
            ? (string) $incident->reference_no
            : '#'.$incident->id;

        return 'Service Case '.$number;
    }

    private function matchedRuleLabel(IncomingEmailMessage $message): string
    {
        $rule = $message->matchedLearningRule;

        if ($rule === null) {
            return 'None';
        }

        return $rule->rule_type->label().' → '.$rule->decision_type->label()
            .' ('.$rule->match_value.')';
    }

    /**
     * @param  array<string, mixed>  $explanation
     */
    private function previousConfirmationsLabel(array $explanation, ?int $timesUsed): string
    {
        if (! ($explanation['previous_operator_confirmation'] ?? false)) {
            return 'No prior confirmation';
        }

        if ($timesUsed !== null && $timesUsed > 0) {
            return 'Yes · used '.$timesUsed.'×';
        }

        return 'Yes · operator confirmed';
    }

    private function gmailUrl(IncomingEmailMessage $message): ?string
    {
        $rfc = trim((string) $message->rfc_message_id);

        if ($rfc !== '') {
            $normalized = trim($rfc, '<>');

            return 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.$normalized;
        }

        if (filled($message->from_email) && filled($message->subject)) {
            $query = 'from:'.$message->from_email.' subject:'.$message->subject;

            return 'https://mail.google.com/mail/u/0/#search/'.rawurlencode($query);
        }

        return null;
    }

    private function userLabel(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'firstName') && filled($user->firstName())) {
            return $user->firstName();
        }

        return $user->name;
    }

    private function fullPreview(?string $preview): string
    {
        $text = trim((string) preg_replace("/[ \t]+/u", ' ', (string) $preview));
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", $text));

        if ($text === '') {
            return 'No preview available.';
        }

        return Str::limit($text, 1200);
    }
}
