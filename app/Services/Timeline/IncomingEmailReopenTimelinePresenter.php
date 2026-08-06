<?php

namespace App\Services\Timeline;

use App\Models\AuditLog;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

/**
 * Presentation helpers for inbound-email reopen timeline cards (no business side-effects).
 */
class IncomingEmailReopenTimelinePresenter
{
    public const REOPEN_BODY = 'This email automatically reopened the previously closed Service Case.';

    public const STORY_KEY = 'incoming_email_case_reopened';

    /**
     * @param  Collection<int, IncomingEmailMessage>  $messages
     * @return array{reopens: array<int, AuditLog>, assignments: array<int, AuditLog>}
     */
    public function indexForMessages(Collection $messages): array
    {
        $messageIds = $messages->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($messageIds === []) {
            return ['reopens' => [], 'assignments' => []];
        }

        $reopens = AuditLog::query()
            ->where('event', 'incoming_email.case_reopened')
            ->whereIn('new_values->incoming_email_message_id', $messageIds)
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (AuditLog $audit): int => (int) ($audit->new_values['incoming_email_message_id'] ?? 0))
            ->all();

        $assignments = AuditLog::query()
            ->where('event', 'service_case.assigned')
            ->whereIn('new_values->assignment_method', [
                'inbound_email_reopen_previous_owner',
                'inbound_email_reopen_refund_desk',
            ])
            ->whereIn('new_values->incoming_email_message_id', $messageIds)
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (AuditLog $audit): int => (int) ($audit->new_values['incoming_email_message_id'] ?? 0))
            ->all();

        return [
            'reopens' => $reopens,
            'assignments' => $assignments,
        ];
    }

    public function isReopenRemarkBody(?string $body): bool
    {
        if ($body === null || trim($body) === '') {
            return false;
        }

        return str_contains(strtolower($body), 'service case reopened by inbound email');
    }

    /**
     * Operator-facing fields (never include technical identifiers).
     *
     * @return list<array{label: string, value: string}>
     */
    public function displayFields(
        IncomingEmailMessage $message,
        ?AuditLog $reopenAudit = null,
    ): array {
        $occurredAt = $message->received_at ?? $message->created_at ?? now();
        $preview = $message->displayPreview();
        $sender = filled($message->from_name)
            ? $message->from_name.' <'.$message->from_email.'>'
            : (string) $message->from_email;

        $reopenedBy = is_string($reopenAudit?->new_values['reopened_by_label'] ?? null)
            ? (string) $reopenAudit->new_values['reopened_by_label']
            : null;
        $assignedBecause = is_string($reopenAudit?->new_values['assigned_because_label'] ?? null)
            ? (string) $reopenAudit->new_values['assigned_because_label']
            : null;

        return array_values(array_filter([
            filled($message->subject) ? [
                'label' => 'Subject',
                'value' => (string) $message->subject,
            ] : null,
            filled($message->from_email) ? [
                'label' => 'Sender',
                'value' => $sender,
            ] : null,
            [
                'label' => 'Received',
                'value' => AppDateFormatter::timelineDatetime($occurredAt) ?? '—',
            ],
            filled($reopenedBy) ? [
                'label' => 'Reopened By',
                'value' => $reopenedBy,
            ] : null,
            filled($assignedBecause) ? [
                'label' => 'Assigned Because',
                'value' => $assignedBecause,
            ] : null,
            filled($preview) ? [
                'label' => 'Preview',
                'value' => (string) $preview,
            ] : null,
        ]));
    }

    /**
     * Hidden behind Technical Details.
     *
     * @return list<array{label: string, value: string}>
     */
    public function technicalFields(IncomingEmailMessage $message): array
    {
        return array_values(array_filter([
            filled($message->rfc_message_id) ? [
                'label' => 'Message ID',
                'value' => (string) $message->rfc_message_id,
            ] : null,
            filled($message->thread_id) ? [
                'label' => 'Thread ID',
                'value' => (string) $message->thread_id,
            ] : null,
            filled($message->provider_message_id) ? [
                'label' => 'Gmail Message ID',
                'value' => (string) $message->provider_message_id,
            ] : null,
            filled($message->mailbox) ? [
                'label' => 'Mailbox',
                'value' => (string) $message->mailbox,
            ] : null,
        ]));
    }

    /**
     * @return list<string>
     */
    public function actionBadges(
        IncomingEmailMessage $message,
        ?AuditLog $assignmentAudit = null,
    ): array {
        $badges = ['Case Reopened'];

        $message->loadMissing('incident.order');
        $incident = $message->incident;
        if ($incident !== null
            && $incident->high_priority
            && ! $incident->order?->isInquiryOrder()) {
            $badges[] = 'Priority Raised';
        }

        if ($assignmentAudit instanceof AuditLog) {
            $assigneeId = $assignmentAudit->new_values['assigned_to_user_id'] ?? null;
            if (is_numeric($assigneeId)) {
                $assignee = User::query()->find((int) $assigneeId);
                if ($assignee instanceof User) {
                    $badges[] = 'Assigned to '.$assignee->firstName();
                }
            }
        }

        return $badges;
    }
}
