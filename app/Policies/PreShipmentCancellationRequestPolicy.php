<?php

namespace App\Policies;

use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;

class PreShipmentCancellationRequestPolicy
{
    private const PERM_SUBMIT = 'preship_cancellations.submit';

    private const PERM_VIEW = 'preship_cancellations.view';

    private const PERM_CENTRAL = 'preship_cancellations.central';

    private const PERM_REVIEW = 'preship_cancellations.review';

    private const PERM_APPROVE = 'preship_cancellations.approve';

    private const PERM_REJECT = 'preship_cancellations.reject';

    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
    ) {}

    public function create(User $user): bool
    {
        return $this->feature->enabled()
            && $user->can(self::PERM_SUBMIT);
    }

    public function submit(User $user): bool
    {
        return $this->create($user);
    }

    public function viewAny(User $user): bool
    {
        if (! $this->feature->enabled()) {
            return false;
        }

        return $user->can(self::PERM_VIEW)
            || $user->can(self::PERM_CENTRAL)
            || $user->can(self::PERM_REVIEW);
    }

    public function view(User $user, PreShipmentCancellationRequest $request): bool
    {
        if (! $this->feature->enabled()) {
            return false;
        }

        if ($user->can(self::PERM_CENTRAL)
            || $user->can(self::PERM_REVIEW)) {
            return true;
        }

        return $user->can(self::PERM_VIEW)
            && (int) $request->submitted_by_user_id === (int) $user->id;
    }

    public function viewCentral(User $user): bool
    {
        return $this->feature->enabled()
            && (
                $user->can(self::PERM_CENTRAL)
                || $user->can(self::PERM_REVIEW)
            );
    }

    public function approve(User $user, PreShipmentCancellationRequest $request): bool
    {
        return $this->feature->enabled()
            && $user->can(self::PERM_APPROVE);
    }

    public function reject(User $user, PreShipmentCancellationRequest $request): bool
    {
        return $this->feature->enabled()
            && $user->can(self::PERM_REJECT);
    }

    public function linkRefund(User $user, PreShipmentCancellationRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    public function retryIncident(User $user, PreShipmentCancellationRequest $request): bool
    {
        if (! $this->feature->enabled()) {
            return false;
        }

        return $this->view($user, $request)
            && (
                $user->can(self::PERM_SUBMIT)
                || $user->can(self::PERM_REVIEW)
            );
    }
}
