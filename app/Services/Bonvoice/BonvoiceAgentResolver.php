<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Services\Interakt\InteraktCustomerMatcher;

class BonvoiceAgentResolver
{
    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    public function resolveUserForCall(BonvoiceCallEvent $event): ?User
    {
        $agentNumber = $this->agentPhoneNumber($event);

        if (! filled($agentNumber)) {
            return null;
        }

        return $this->resolveUserByPhone($agentNumber);
    }

    public function resolveAgentFirstNameForCall(BonvoiceCallEvent $event): ?string
    {
        $firstName = $this->resolveUserForCall($event)?->firstName();

        return filled($firstName) ? $firstName : null;
    }

    public function resolveUserByPhone(?string $phoneNumber): ?User
    {
        $candidates = $this->customerMatcher->channelPhoneCandidates($phoneNumber);

        if ($candidates === []) {
            return null;
        }

        return User::query()
            ->whereNotNull('bonvoice_extension')
            ->get()
            ->first(fn (User $user): bool => $this->phoneNumbersMatch($user->bonvoice_extension, $phoneNumber));
    }

    private function agentPhoneNumber(BonvoiceCallEvent $event): ?string
    {
        if ($this->isInbound($event->direction)) {
            return $event->destination_number;
        }

        return $event->source_number;
    }

    private function isInbound(?string $direction): bool
    {
        $normalized = strtolower((string) $direction);

        return in_array($normalized, ['inbound', 'in', 'incoming'], true);
    }

    private function phoneNumbersMatch(?string $storedPhone, ?string $incomingPhone): bool
    {
        $storedCandidates = $this->customerMatcher->channelPhoneCandidates($storedPhone);
        $incomingCandidates = $this->customerMatcher->channelPhoneCandidates($incomingPhone);

        if ($storedCandidates === [] || $incomingCandidates === []) {
            return false;
        }

        return array_intersect($storedCandidates, $incomingCandidates) !== [];
    }
}
