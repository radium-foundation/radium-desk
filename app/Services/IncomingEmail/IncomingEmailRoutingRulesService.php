<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\IncomingEmailRouteDecision;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailSmartRoute;
use App\Models\IncomingEmailMessage;

/**
 * Deterministic, config-driven routing rules for Phase 1.3 smart routing.
 *
 * Evaluation order (after active/closed case handling):
 * 1. Existing customer + order + no SC → existing_customer_new_case
 * 2. Refund enquiry
 * 3. Sales enquiry
 * 4. Support enquiry
 * 5. Everything else → needs_human
 */
class IncomingEmailRoutingRulesService
{
    public function isEnabled(): bool
    {
        return (bool) config('inbound_email.enabled')
            && (bool) config('inbound_email.smart_routing_enabled');
    }

    /**
     * @param  array{
     *     order: mixed,
     *     incident: mixed,
     *     closed_incident: mixed,
     *     reason: ?string
     * }  $match
     */
    public function decide(
        IncomingEmailMessage $message,
        array $match,
        IncomingEmailClassification $classification,
    ): IncomingEmailRouteDecision {
        if (($match['reason'] ?? null) === 'historical_customer' && ($match['order'] ?? null) !== null) {
            return new IncomingEmailRouteDecision(
                route: IncomingEmailSmartRoute::ExistingCustomerNewCase,
                reason: 'existing_customer_no_service_case',
                classification: $this->normalizeExistingCustomerClassification($classification),
            );
        }

        if ($this->matchesRefund($message, $classification)) {
            return new IncomingEmailRouteDecision(
                route: IncomingEmailSmartRoute::RefundEnquiry,
                reason: $this->refundMatchReason($message, $classification),
                classification: IncomingEmailClassification::Refund,
            );
        }

        if ($this->matchesSales($message, $classification)) {
            return new IncomingEmailRouteDecision(
                route: IncomingEmailSmartRoute::SalesEnquiry,
                reason: $this->salesMatchReason($message, $classification),
                classification: IncomingEmailClassification::PossibleSalesLead,
            );
        }

        if ($this->matchesSupport($message, $classification)) {
            return new IncomingEmailRouteDecision(
                route: IncomingEmailSmartRoute::SupportEnquiry,
                reason: $this->supportMatchReason($message, $classification),
                classification: $this->normalizeSupportClassification($classification),
            );
        }

        return new IncomingEmailRouteDecision(
            route: IncomingEmailSmartRoute::NeedsHuman,
            reason: 'unclassified_actionable_email',
            classification: $this->normalizeUnknownClassification($classification),
        );
    }

    private function normalizeExistingCustomerClassification(
        IncomingEmailClassification $classification,
    ): IncomingEmailClassification {
        if ($classification === IncomingEmailClassification::Refund) {
            return IncomingEmailClassification::Refund;
        }

        if ($classification === IncomingEmailClassification::PossibleSalesLead) {
            return IncomingEmailClassification::PossibleSalesLead;
        }

        if (in_array($classification, [
            IncomingEmailClassification::Support,
            IncomingEmailClassification::ExistingCustomer,
            IncomingEmailClassification::Appointment,
        ], true)) {
            return $classification;
        }

        return IncomingEmailClassification::ExistingCustomer;
    }

    private function normalizeSupportClassification(
        IncomingEmailClassification $classification,
    ): IncomingEmailClassification {
        if ($classification === IncomingEmailClassification::Appointment) {
            return IncomingEmailClassification::Appointment;
        }

        if ($classification === IncomingEmailClassification::ExistingCustomer) {
            return IncomingEmailClassification::ExistingCustomer;
        }

        return IncomingEmailClassification::Support;
    }

    private function normalizeUnknownClassification(
        IncomingEmailClassification $classification,
    ): IncomingEmailClassification {
        if ($classification === IncomingEmailClassification::PossibleSalesLead) {
            return IncomingEmailClassification::UnknownCustomer;
        }

        return $classification;
    }

    private function matchesRefund(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): bool {
        if ($classification === IncomingEmailClassification::Refund) {
            return true;
        }

        return $this->matchesRuleSet($message, config('inbound_email.routing.refund', []));
    }

    private function matchesSales(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): bool {
        if ($classification === IncomingEmailClassification::PossibleSalesLead) {
            return true;
        }

        return $this->matchesRuleSet($message, config('inbound_email.routing.sales', []));
    }

    public function matchesSalesSignals(IncomingEmailMessage $message): bool
    {
        $classification = $message->classification ?? IncomingEmailClassification::UnknownCustomer;

        return $this->matchesSales($message, $classification);
    }

    private function matchesSupport(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): bool {
        if (in_array($classification, [
            IncomingEmailClassification::Support,
            IncomingEmailClassification::ExistingCustomer,
            IncomingEmailClassification::Appointment,
        ], true)) {
            return true;
        }

        return $this->matchesRuleSet($message, config('inbound_email.routing.support', []));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function matchesRuleSet(IncomingEmailMessage $message, array $rules): bool
    {
        $channel = strtolower(trim((string) ($message->channel ?? '')));
        $mailboxChannels = array_map('strtolower', (array) ($rules['mailbox_channels'] ?? []));

        if ($channel !== '' && in_array($channel, $mailboxChannels, true)) {
            return true;
        }

        $recipients = array_map('strtolower', (array) ($rules['recipient_addresses'] ?? []));
        $mailbox = strtolower(trim((string) $message->mailbox));

        if ($mailbox !== '' && in_array($mailbox, $recipients, true)) {
            return true;
        }

        $fromAliases = array_map('strtolower', (array) ($rules['from_aliases'] ?? []));
        $fromEmail = strtolower(trim((string) $message->from_email));

        if ($fromEmail !== '' && in_array($fromEmail, $fromAliases, true)) {
            return true;
        }

        $haystack = $this->keywordHaystack($message);
        $keywords = (array) ($rules['subject_keywords'] ?? []);

        foreach ($keywords as $keyword) {
            $needle = strtolower(trim((string) $keyword));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function refundMatchReason(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): string {
        if ($classification === IncomingEmailClassification::Refund) {
            return 'classification_refund';
        }

        return $this->firstRuleMatchReason($message, config('inbound_email.routing.refund', []))
            ?? 'refund_routing_rules';
    }

    private function salesMatchReason(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): string {
        if ($classification === IncomingEmailClassification::PossibleSalesLead) {
            return 'classification_sales_lead';
        }

        return $this->firstRuleMatchReason($message, config('inbound_email.routing.sales', []))
            ?? 'sales_routing_rules';
    }

    private function supportMatchReason(
        IncomingEmailMessage $message,
        IncomingEmailClassification $classification,
    ): string {
        if ($classification === IncomingEmailClassification::Support) {
            return 'classification_support';
        }

        if ($classification === IncomingEmailClassification::ExistingCustomer) {
            return 'classification_existing_customer';
        }

        if ($classification === IncomingEmailClassification::Appointment) {
            return 'classification_appointment';
        }

        return $this->firstRuleMatchReason($message, config('inbound_email.routing.support', []))
            ?? 'support_routing_rules';
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function firstRuleMatchReason(IncomingEmailMessage $message, array $rules): ?string
    {
        $channel = strtolower(trim((string) ($message->channel ?? '')));
        $mailboxChannels = array_map('strtolower', (array) ($rules['mailbox_channels'] ?? []));

        if ($channel !== '' && in_array($channel, $mailboxChannels, true)) {
            return 'mailbox_channel:'.$channel;
        }

        $recipients = array_map('strtolower', (array) ($rules['recipient_addresses'] ?? []));
        $mailbox = strtolower(trim((string) $message->mailbox));

        if ($mailbox !== '' && in_array($mailbox, $recipients, true)) {
            return 'recipient:'.$mailbox;
        }

        $fromAliases = array_map('strtolower', (array) ($rules['from_aliases'] ?? []));
        $fromEmail = strtolower(trim((string) $message->from_email));

        if ($fromEmail !== '' && in_array($fromEmail, $fromAliases, true)) {
            return 'from_alias:'.$fromEmail;
        }

        $haystack = $this->keywordHaystack($message);

        foreach ((array) ($rules['subject_keywords'] ?? []) as $keyword) {
            $needle = strtolower(trim((string) $keyword));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return 'subject_keyword:'.$needle;
            }
        }

        return null;
    }

    private function keywordHaystack(IncomingEmailMessage $message): string
    {
        return strtolower(trim(
            (string) $message->subject.' '.
            (string) $message->from_email.' '.
            (string) $message->from_name.' '.
            (string) $message->preview
        ));
    }
}
