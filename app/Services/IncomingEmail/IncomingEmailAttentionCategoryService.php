<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailAttentionCategory;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use Illuminate\Support\Collection;

class IncomingEmailAttentionCategoryService
{
    public function __construct(
        private readonly IncomingEmailPriorityPhraseService $priorityPhraseService,
        private readonly IncomingEmailCustomerMatcher $customerMatcher,
        private readonly IncomingEmailRoutingRulesService $routingRulesService,
    ) {}

    /**
     * @return array{sales: int, orders: int, priority: int}
     */
    public function aggregateCounts(): array
    {
        $messages = IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ])
            ->get([
                'id',
                'mailbox',
                'channel',
                'from_email',
                'from_name',
                'subject',
                'preview',
                'classification',
                'order_id',
            ]);

        $knownOrderEmails = $this->knownCustomerEmails($messages);

        $counts = [
            IncomingEmailAttentionCategory::Sales->value => 0,
            IncomingEmailAttentionCategory::Orders->value => 0,
            IncomingEmailAttentionCategory::Priority->value => 0,
        ];

        foreach ($messages as $message) {
            $category = $this->categorize($message, $knownOrderEmails);
            $counts[$category->value]++;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, string>  $knownOrderEmails
     */
    public function categorize(IncomingEmailMessage $message, Collection $knownOrderEmails): IncomingEmailAttentionCategory
    {
        // Read-only: never write audit logs from dashboard/KPI paths.
        // Priority audits are created during ingest/sync via matchAndAudit().
        if ($this->priorityPhraseService->match($message) !== null) {
            return IncomingEmailAttentionCategory::Priority;
        }

        if ($this->isSales($message)) {
            return IncomingEmailAttentionCategory::Sales;
        }

        if ($this->isOrders($message, $knownOrderEmails)) {
            return IncomingEmailAttentionCategory::Orders;
        }

        return IncomingEmailAttentionCategory::Sales;
    }

    private function isSales(IncomingEmailMessage $message): bool
    {
        if ($message->classification === IncomingEmailClassification::PossibleSalesLead) {
            return true;
        }

        $channel = strtolower(trim((string) ($message->channel ?? '')));

        if ($channel === 'sales') {
            return true;
        }

        return $this->routingRulesService->matchesSalesSignals($message);
    }

    /**
     * @param  Collection<int, string>  $knownOrderEmails
     */
    private function isOrders(IncomingEmailMessage $message, Collection $knownOrderEmails): bool
    {
        if ($message->order_id !== null) {
            return true;
        }

        if (in_array($message->classification, [
            IncomingEmailClassification::ExistingCustomer,
            IncomingEmailClassification::Support,
            IncomingEmailClassification::Refund,
            IncomingEmailClassification::Appointment,
        ], true)) {
            return true;
        }

        foreach ($this->customerMatcher->emailCandidates($message->from_email) as $candidate) {
            if ($knownOrderEmails->contains($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, IncomingEmailMessage>  $messages
     * @return Collection<int, string>
     */
    private function knownCustomerEmails(Collection $messages): Collection
    {
        $candidates = [];

        foreach ($messages as $message) {
            foreach ($this->customerMatcher->emailCandidates($message->from_email) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            return collect();
        }

        return Order::query()
            ->whereIn('customer_email', $candidates)
            ->pluck('customer_email');
    }
}
