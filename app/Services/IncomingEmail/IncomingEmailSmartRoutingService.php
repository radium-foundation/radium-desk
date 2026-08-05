<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\IncomingEmailRouteDecision;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailSmartRoute;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;

class IncomingEmailSmartRoutingService
{
    public function __construct(
        private readonly IncomingEmailRoutingRulesService $routingRules,
        private readonly IncomingEmailServiceCaseCreateService $serviceCaseCreateService,
        private readonly IncomingEmailServiceCaseCategoryMapper $categoryMapper,
        private readonly IncomingEmailSmartRoutingAssignmentService $routingAssignmentService,
        private readonly IncomingEmailLinkService $linkService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isEnabled(): bool
    {
        return $this->routingRules->isEnabled();
    }

    /**
     * @param  array{
     *     order: mixed,
     *     incident: mixed,
     *     closed_incident: mixed,
     *     reason: ?string
     * }  $match
     */
    public function processUnmatched(
        IncomingEmailMessage $message,
        array $match,
        IncomingEmailClassification $classification,
        User $actor,
    ): void {
        $decision = $this->routingRules->decide($message, $match, $classification);

        if ($decision->route === IncomingEmailSmartRoute::NeedsHuman) {
            $this->markNeedsHuman($message, $decision, $actor);

            return;
        }

        if ($this->categoryMapper->isInternalOperational($decision->classification)) {
            $this->markNeedsHuman($message, new IncomingEmailRouteDecision(
                route: IncomingEmailSmartRoute::NeedsHuman,
                reason: 'internal_operational_email',
                classification: $decision->classification,
            ), $actor);

            return;
        }

        $result = $this->createLinkAndAssign(
            message: $message->fresh(),
            decision: $decision,
            actor: $actor,
            order: $match['order'] ?? null,
        );

        $this->routingAssignmentService->assignForRoute(
            incident: $result['incident']->fresh(['assignee', 'order']),
            message: $result['message']->fresh(),
            route: $decision->route,
            actor: $actor,
            routeReason: $decision->reason,
            order: $match['order'] ?? null,
        );
    }

    /**
     * @return array{incident: \App\Models\Incident, message: IncomingEmailMessage, created: bool}
     */
    private function createLinkAndAssign(
        IncomingEmailMessage $message,
        IncomingEmailRouteDecision $decision,
        User $actor,
        mixed $order,
    ): array {
        if ($decision->route === IncomingEmailSmartRoute::ExistingCustomerNewCase && $order instanceof Order) {
            return $this->serviceCaseCreateService->createLinkAndRouteForOrder(
                order: $order,
                message: $message,
                actor: $actor,
                classification: $decision->classification,
                skipAssignment: true,
            );
        }

        return $this->serviceCaseCreateService->createLinkAndRouteForUnknownCustomer(
            message: $message,
            actor: $actor,
            classification: $decision->classification,
            skipAssignment: true,
        );
    }

    private function markNeedsHuman(
        IncomingEmailMessage $message,
        IncomingEmailRouteDecision $decision,
        User $actor,
    ): void {
        $message->update([
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'ignore_reason' => $decision->reason,
            'classification' => $decision->classification,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.routed',
            auditable: $message->fresh(),
            newValues: [
                'route' => IncomingEmailSmartRoute::NeedsHuman->value,
                'route_label' => IncomingEmailSmartRoute::NeedsHuman->label(),
                'reason' => $decision->reason,
                'assignment_source' => 'none',
                'round_robin_user_id' => null,
                'mailbox' => $message->mailbox,
                'classification' => $decision->classification->value,
                'incoming_email_message_id' => $message->id,
                'routed_at' => now()->toIso8601String(),
            ],
        );

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.needs_review',
            auditable: $message->fresh(),
            newValues: [
                'reason' => $decision->reason,
                'classification' => $decision->classification->value,
                'mailbox' => $message->mailbox,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
            ],
        );
    }
}
