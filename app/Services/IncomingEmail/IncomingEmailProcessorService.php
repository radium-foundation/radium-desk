<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use App\Models\IncomingEmailMessage;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\ServiceCasePriorityService;
use Illuminate\Support\Facades\DB;
use Throwable;

class IncomingEmailProcessorService
{
    public function __construct(
        private readonly IncomingEmailFilterService $filterService,
        private readonly IncomingEmailCustomerMatcher $customerMatcher,
        private readonly IncomingEmailLinkService $linkService,
        private readonly IncomingEmailHistoricalAssociationService $historicalAssociationService,
        private readonly IncomingEmailAssignmentService $assignmentService,
        private readonly ServiceCasePriorityService $priorityService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function process(IncomingEmailMessage $message): void
    {
        if (! config('inbound_email.enabled')) {
            return;
        }

        if (in_array($message->status, [
            IncomingEmailMessageStatus::Linked,
            IncomingEmailMessageStatus::HistoricalCustomer,
            IncomingEmailMessageStatus::Ignored,
        ], true)) {
            return;
        }

        $actor = $this->automationIdentity->systemUser();

        try {
            $message->update([
                'status' => IncomingEmailMessageStatus::Processing,
                'processing_error' => null,
            ]);

            $filter = $this->filterService->evaluate($message);

            if ($filter['ignored']) {
                $this->markIgnored($message, (string) $filter['reason'], $actor->id);

                return;
            }

            DB::transaction(function () use ($message, $actor): void {
                $match = $this->customerMatcher->resolve($message->fresh());

                if ($match['incident'] === null) {
                    if ($match['order'] !== null && ($match['reason'] ?? null) === 'historical_customer') {
                        $this->historicalAssociationService->associate(
                            $match['order'],
                            $message->fresh(),
                            $actor,
                        );

                        return;
                    }

                    $this->markIgnored(
                        $message,
                        (string) ($match['reason'] ?? 'unknown_customer'),
                        $actor->id,
                    );

                    return;
                }

                $incident = $match['incident'];
                $this->linkService->link($incident, $message->fresh(), $actor);

                $incident = $this->priorityService->applyInboundLinkBoost(
                    $incident->fresh(['order', 'assignee']),
                    IntakeChannel::Email,
                    $actor,
                );

                if ($incident->assigned_to_user_id === null) {
                    $this->assignmentService->assignIfUnassigned($incident, $actor);
                }
            });
        } catch (Throwable $exception) {
            $message->update([
                'status' => IncomingEmailMessageStatus::Failed,
                'processing_error' => $exception->getMessage(),
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.processing_failed',
                auditable: $message->fresh(),
                newValues: [
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    private function markIgnored(IncomingEmailMessage $message, string $reason, int $actorId): void
    {
        $message->update([
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => $reason,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actorId,
            event: 'incoming_email.ignored',
            auditable: $message->fresh(),
            newValues: [
                'reason' => $reason,
                'mailbox' => $message->mailbox,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
            ],
        );
    }
}
