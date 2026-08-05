<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncomingEmailClassification;
use App\Enums\IntakeChannel;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeService;
use App\Services\QuickServiceRequestService;
use App\Services\ServiceCasePriorityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates (or reuses) Service Cases for inbound customer email.
 *
 * Not wired into the processor until later steps. Safe while
 * inbound_email.auto_create_service_case remains false.
 */
class IncomingEmailServiceCaseCreateService
{
    public function __construct(
        private readonly QuickServiceRequestService $quickServiceRequestService,
        private readonly CustomerIntakeService $customerIntakeService,
        private readonly IncomingEmailServiceCaseCategoryMapper $categoryMapper,
        private readonly IncomingEmailLinkService $linkService,
        private readonly IncomingEmailAssignmentService $assignmentService,
        private readonly ServiceCasePriorityService $priorityService,
        private readonly IncomingEmailCustomerMatcher $customerMatcher,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('inbound_email.auto_create_service_case', false)
            || (bool) config('inbound_email.smart_routing_enabled', false);
    }

    /**
     * Ensure exactly one active Service Case exists for the order.
     *
     * Concurrent callers serialize on the order row, recheck for an active SC,
     * and create only when none exists.
     *
     * @return array{incident: Incident, created: bool}
     */
    public function ensureActiveForOrder(
        Order $order,
        User $actor,
        IncomingEmailClassification $classification,
        ?string $notes = null,
        ?string $title = null,
    ): array {
        $this->categoryMapper->assertCustomerFacing($classification);

        return DB::transaction(function () use ($order, $actor, $classification, $notes, $title): array {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->findActiveIncidentForOrder($lockedOrder->id);

            if ($existing instanceof Incident) {
                return [
                    'incident' => $existing->fresh(['order', 'assignee']),
                    'created' => false,
                ];
            }

            $category = $this->categoryMapper->category($classification);
            $incident = $this->quickServiceRequestService->createForOrder(
                user: $actor,
                order: $lockedOrder,
                source: IncidentSource::Email,
                notes: $notes,
                highPriority: false,
                title: $title ?? $this->defaultTitle($notes),
                category: $category,
                assignOnCreate: false,
            );

            return [
                'incident' => $incident->fresh(['order', 'assignee']),
                'created' => true,
            ];
        });
    }

    /**
     * Create an INQ-prefixed inquiry Order + Service Case for an unmatched customer email.
     *
     * Idempotent for the same from-address when an active inquiry SC already exists
     * (lock on matching email candidates, then recheck before create).
     *
     * @return array{incident: Incident, created: bool}
     */
    public function ensureForUnknownCustomer(
        IncomingEmailMessage $message,
        User $actor,
        IncomingEmailClassification $classification,
    ): array {
        $this->categoryMapper->assertCustomerFacing($classification);

        $emailCandidates = $this->customerMatcher->emailCandidates($message->from_email);

        if ($emailCandidates === []) {
            throw new \InvalidArgumentException('Incoming email message has no usable from_email.');
        }

        // Serialize concurrent unknown-customer creates for the same address
        // before any order row exists to lock.
        $lock = Cache::lock(
            'inbound_email:auto_sc:'.hash('sha256', implode('|', $emailCandidates)),
            15,
        );

        return $lock->block(10, function () use ($message, $actor, $classification, $emailCandidates): array {
            return DB::transaction(function () use ($message, $actor, $classification, $emailCandidates): array {
                $matchedOrders = Order::query()
                    ->whereIn('customer_email', $emailCandidates)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($matchedOrders as $matchedOrder) {
                    $existing = $this->findActiveIncidentForOrder($matchedOrder->id);

                    if ($existing instanceof Incident) {
                        return [
                            'incident' => $existing->fresh(['order', 'assignee']),
                            'created' => false,
                        ];
                    }
                }

                if ($matchedOrders->isNotEmpty()) {
                    return $this->ensureActiveForOrder(
                        order: $matchedOrders->sortByDesc('id')->first(),
                        actor: $actor,
                        classification: $classification,
                        notes: $this->notesFromMessage($message),
                        title: $this->titleFromMessage($message),
                    );
                }

                $intent = $this->categoryMapper->intent($classification);
                $category = $this->categoryMapper->category($classification);

                $incident = $this->customerIntakeService->createNewContact(
                    user: $actor,
                    intent: $intent,
                    source: IncidentSource::Email,
                    customerName: $this->customerNameFromMessage($message),
                    phone: null,
                    serialNumber: null,
                    product: null,
                    notes: $this->notesFromMessage($message),
                    highPriority: false,
                    assignOnCreate: false,
                );

                if ($incident->category !== $category) {
                    $incident->update([
                        'category' => $category,
                        'updated_by' => $actor->id,
                    ]);
                }

                $order = $incident->order;
                if ($order !== null) {
                    $order->update([
                        'customer_email' => $emailCandidates[0],
                        'customer_name' => $order->customer_name ?: $this->customerNameFromMessage($message),
                        'updated_by' => $actor->id,
                    ]);
                }

                $incident = $incident->fresh(['order', 'assignee']);

                if ($order === null || ! Order::isInquiryOrderId($order->order_id)) {
                    throw new \RuntimeException(
                        'Inquiry intake did not produce an INQ-prefixed order for inbound email auto-create.',
                    );
                }

                return [
                    'incident' => $incident,
                    'created' => true,
                ];
            });
        });
    }

    /**
     * Ensure SC exists, link the message, boost priority, and route ownership.
     *
     * @return array{incident: Incident, message: IncomingEmailMessage, created: bool}
     */
    public function createLinkAndRouteForOrder(
        Order $order,
        IncomingEmailMessage $message,
        User $actor,
        IncomingEmailClassification $classification,
        bool $skipAssignment = false,
    ): array {
        $result = $this->ensureActiveForOrder(
            order: $order,
            actor: $actor,
            classification: $classification,
            notes: $this->notesFromMessage($message),
            title: $this->titleFromMessage($message),
        );

        return $this->linkBoostAndRoute(
            incident: $result['incident'],
            message: $message,
            actor: $actor,
            classification: $classification,
            created: $result['created'],
            skipAssignment: $skipAssignment,
        );
    }

    /**
     * Ensure SC exists for unknown customer, link, boost, and route.
     *
     * @return array{incident: Incident, message: IncomingEmailMessage, created: bool}
     */
    public function createLinkAndRouteForUnknownCustomer(
        IncomingEmailMessage $message,
        User $actor,
        IncomingEmailClassification $classification,
        bool $skipAssignment = false,
    ): array {
        $result = $this->ensureForUnknownCustomer($message, $actor, $classification);

        return $this->linkBoostAndRoute(
            incident: $result['incident'],
            message: $message,
            actor: $actor,
            classification: $classification,
            created: $result['created'],
            skipAssignment: $skipAssignment,
        );
    }

    /**
     * @return array{incident: Incident, message: IncomingEmailMessage, created: bool}
     */
    private function linkBoostAndRoute(
        Incident $incident,
        IncomingEmailMessage $message,
        User $actor,
        IncomingEmailClassification $classification,
        bool $created,
        bool $skipAssignment = false,
    ): array {
        $linkedMessage = $this->linkService->link(
            $incident,
            $message->fresh(),
            $actor,
            $classification,
        );

        $incident = $this->priorityService->applyInboundLinkBoost(
            $incident->fresh(['order', 'assignee']),
            IntakeChannel::Email,
            $actor,
        );

        if (! $skipAssignment) {
            $incident = $this->assignmentService->routeLinkedEmail(
                $incident->fresh(['assignee', 'order']),
                $linkedMessage->fresh(),
                $actor,
            );
        }

        return [
            'incident' => $incident->fresh(['order', 'assignee']),
            'message' => $linkedMessage->fresh(),
            'created' => $created,
        ];
    }

    private function findActiveIncidentForOrder(int $orderId): ?Incident
    {
        return Incident::query()
            ->where('order_id', $orderId)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();
    }

    private function notesFromMessage(IncomingEmailMessage $message): string
    {
        $subject = trim((string) $message->subject);
        $preview = trim((string) $message->preview);

        $parts = array_values(array_filter([
            $subject !== '' ? 'Subject: '.$subject : null,
            $preview !== '' ? $preview : null,
        ]));

        return $parts === [] ? 'Inbound email' : implode("\n\n", $parts);
    }

    private function titleFromMessage(IncomingEmailMessage $message): string
    {
        $subject = trim((string) $message->subject);

        if ($subject === '') {
            return 'Inbound email';
        }

        return Str::limit('Inbound email — '.$subject, 120);
    }

    private function defaultTitle(?string $notes): string
    {
        if ($notes === null || trim($notes) === '') {
            return 'Inbound email';
        }

        return Str::limit('Inbound email — '.trim($notes), 120);
    }

    private function customerNameFromMessage(IncomingEmailMessage $message): string
    {
        $name = trim((string) $message->from_name);

        if ($name !== '') {
            return Str::limit($name, 120);
        }

        return Str::limit((string) $message->from_email, 120);
    }
}
