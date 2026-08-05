<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IntakeChannel;
use App\Models\IncomingEmailIgnoreStat;
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
        private readonly IncomingEmailClassifierService $classifierService,
        private readonly IncomingEmailCustomerMatcher $customerMatcher,
        private readonly IncomingEmailLinkService $linkService,
        private readonly IncomingEmailHistoricalAssociationService $historicalAssociationService,
        private readonly IncomingEmailAssignmentService $assignmentService,
        private readonly IncomingEmailClosedCaseReopenService $closedCaseReopenService,
        private readonly IncomingEmailServiceCaseCreateService $serviceCaseCreateService,
        private readonly IncomingEmailServiceCaseCategoryMapper $categoryMapper,
        private readonly IncomingEmailSmartRoutingService $smartRoutingService,
        private readonly ServiceCasePriorityService $priorityService,
        private readonly IncomingEmailPriorityPhraseService $priorityPhraseService,
        private readonly IncomingEmailIntakeCounterService $intakeCounterService,
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
            IncomingEmailMessageStatus::NeedsReview,
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
                $reason = (string) $filter['reason'];
                $classification = $this->classifierService->fromFilterReason($reason);
                $this->markIgnored($message, $reason, $classification, $actor->id);

                return;
            }

            // Priority phrase audits belong on ingest/sync only — never on dashboard reads.
            $this->priorityPhraseService->matchAndAudit($message->fresh(), $actor);

            DB::transaction(function () use ($message, $actor): void {
                $fresh = $message->fresh();
                $match = $this->customerMatcher->resolve($fresh);
                $classification = $this->classifierService->classifyOperational($fresh, $match);

                // Phase 1.1 — closed SC for matched order/thread: reopen same case (never duplicate).
                if (($match['closed_incident'] ?? null) !== null) {
                    $this->closedCaseReopenService->reopenLinkAndRoute(
                        closedIncident: $match['closed_incident'],
                        message: $fresh->fresh(),
                        actor: $actor,
                        classification: $classification,
                    );

                    return;
                }

                if ($match['incident'] === null) {
                    if ($this->smartRoutingService->isEnabled()) {
                        $this->smartRoutingService->processUnmatched(
                            message: $fresh->fresh(),
                            match: $match,
                            classification: $classification,
                            actor: $actor,
                        );

                        return;
                    }

                    if ($match['order'] !== null && ($match['reason'] ?? null) === 'historical_customer') {
                        // Branch B — order exists, no active SC and no reopenable closed SC.
                        // Flag off (default): Historical association (unchanged).
                        // Flag on: auto-create SC + link + route for customer-facing mail only.
                        // Internal operational (Finance/HR/Vendor) still parks as Historical.
                        if ($this->shouldAutoCreateCustomerServiceCase($classification)) {
                            $this->serviceCaseCreateService->createLinkAndRouteForOrder(
                                order: $match['order'],
                                message: $fresh->fresh(),
                                actor: $actor,
                                classification: $classification,
                            );

                            return;
                        }

                        $this->historicalAssociationService->associate(
                            $match['order'],
                            $fresh->fresh(),
                            $actor,
                            $classification,
                        );

                        return;
                    }

                    // Branch C — customer email, no matching order.
                    // Flag off (default): NeedsReview (unchanged).
                    // Flag on: INQ Order + Email SC + link + route for customer-facing mail only.
                    // Finance/HR/Vendor/spam/promo/system never auto-create (filter or park).
                    $customerClassification = $classification === IncomingEmailClassification::PossibleSalesLead
                        ? IncomingEmailClassification::PossibleSalesLead
                        : IncomingEmailClassification::UnknownCustomer;

                    if ($this->shouldAutoCreateCustomerServiceCase($classification)) {
                        $this->serviceCaseCreateService->createLinkAndRouteForUnknownCustomer(
                            message: $fresh->fresh(),
                            actor: $actor,
                            classification: $customerClassification,
                        );

                        return;
                    }

                    $this->markNeedsReview(
                        $fresh,
                        $customerClassification,
                        $actor->id,
                        (string) ($match['reason'] ?? 'unknown_customer'),
                    );

                    return;
                }

                $incident = $match['incident'];
                $linkedMessage = $this->linkService->link($incident, $fresh->fresh(), $actor, $classification);

                $incident = $this->priorityService->applyInboundLinkBoost(
                    $incident->fresh(['order', 'assignee']),
                    IntakeChannel::Email,
                    $actor,
                );

                // Ownership routing: existing assignee always wins; never reassign.
                // Unassigned → Communication Intake primary → fallback (no round robin).
                $this->assignmentService->routeLinkedEmail(
                    $incident->fresh(['assignee', 'order']),
                    $linkedMessage->fresh(),
                    $actor,
                );
            });
        } catch (Throwable $exception) {
            $message->update([
                'status' => IncomingEmailMessageStatus::Failed,
                'processing_error' => $exception->getMessage(),
            ]);

            // Idempotent — covers failures before the ingest audit call above.
            $this->priorityPhraseService->matchAndAudit($message->fresh(), $actor);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'incoming_email.processing_failed',
                auditable: $message->fresh(),
                newValues: [
                    'error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        } finally {
            $this->intakeCounterService->forgetDashboardWidgetCache();
        }
    }

    private function shouldAutoCreateCustomerServiceCase(
        IncomingEmailClassification $classification,
    ): bool {
        if (! $this->serviceCaseCreateService->isEnabled()) {
            return false;
        }

        if ($this->categoryMapper->isInternalOperational($classification)) {
            return false;
        }

        return $classification->isOperational();
    }

    private function markIgnored(
        IncomingEmailMessage $message,
        string $reason,
        IncomingEmailClassification $classification,
        int $actorId,
    ): void {
        $message->update([
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => $reason,
            'classification' => $classification,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        IncomingEmailIgnoreStat::incrementReason($reason);

        $this->auditLogService->log(
            userId: $actorId,
            event: 'incoming_email.ignored',
            auditable: $message->fresh(),
            newValues: [
                'reason' => $reason,
                'classification' => $classification->value,
                'mailbox' => $message->mailbox,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
            ],
        );
    }

    private function markNeedsReview(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
        int $actorId,
        string $reason,
    ): void {
        $message->update([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'ignore_reason' => $reason,
            'classification' => $classification,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actorId,
            event: 'incoming_email.needs_review',
            auditable: $message->fresh(),
            newValues: [
                'reason' => $reason,
                'classification' => $classification->value,
                'mailbox' => $message->mailbox,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
            ],
        );
    }
}
