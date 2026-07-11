<?php

namespace App\Services\Customer360;

use App\Data\Eligibility\EligibilityResult;
use App\Models\Incident;
use App\Models\User;
use App\Services\Interakt\CustomerNotRespondingEligibilityService;
use App\Services\Interakt\RequestCorrectSerialEligibilityService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\CustomerCorrection\CustomerCorrectionEligibilityService;
use App\Services\Inquiry\InquiryOrderLinkEligibilityService;
use App\Services\SerialCorrection\SerialCorrectionEligibilityService;

class Customer360ActionVisibilityService
{
    public function __construct(
        private readonly RequestSerialNumberEligibilityService $requestSerialEligibilityService,
        private readonly RequestCorrectSerialEligibilityService $requestCorrectSerialEligibilityService,
        private readonly CustomerNotRespondingEligibilityService $customerNotRespondingEligibilityService,
        private readonly InquiryOrderLinkEligibilityService $inquiryOrderLinkEligibilityService,
        private readonly CustomerCorrectionEligibilityService $customerCorrectionEligibilityService,
        private readonly SerialCorrectionEligibilityService $serialCorrectionEligibilityService,
    ) {}

    /**
     * @return array{
     *     isWaitingForCustomer: bool,
     *     hideWorkflowActions: bool,
     *     canRequestSerialNumber: bool,
     *     canRequestCorrectSerial: bool,
     *     canCustomerNotResponding: bool,
     *     canLinkOrder: bool,
     *     canCorrectCustomerDetails: bool,
     *     canCorrectSerialNumber: bool,
     *     correctCustomerDetailsEligibility: array{allowed: bool, reason: string|null},
     *     correctSerialNumberEligibility: array{allowed: bool, reason: string|null},
     *     showIdentityCorrectionActions: bool,
     *     hasRecommendedActions: bool,
     * }
     */
    public function forIncident(Incident $incident, ?User $user = null): array
    {
        $incident->loadMissing(['order', 'activeWaitingState']);

        $isWaitingForCustomer = $incident->activeWaitingState !== null
            && $incident->activeWaitingState->isActive();
        $hideWorkflowActions = $isWaitingForCustomer;

        $canRequestSerialNumber = ! $hideWorkflowActions
            && $this->requestSerialEligibilityService->canShowAction($incident);
        $canRequestCorrectSerial = ! $hideWorkflowActions
            && $this->requestCorrectSerialEligibilityService->canShowAction($incident);
        $canCustomerNotResponding = ! $hideWorkflowActions
            && $this->customerNotRespondingEligibilityService->canShowAction($incident);
        $canLinkOrder = ! $hideWorkflowActions
            && $user !== null
            && $this->inquiryOrderLinkEligibilityService->canShowAction($incident, $user);

        $correctCustomerDetailsEligibility = $this->eligibilityPayload(
            $user !== null ? $this->customerCorrectionEligibilityService->evaluate($incident, $user) : null,
        );
        $correctSerialNumberEligibility = $this->eligibilityPayload(
            $user !== null ? $this->serialCorrectionEligibilityService->evaluate($incident, $user) : null,
        );

        $hasRecommendedActions = $canRequestSerialNumber
            || $canRequestCorrectSerial
            || $canCustomerNotResponding
            || $canLinkOrder;

        return [
            'isWaitingForCustomer' => $isWaitingForCustomer,
            'hideWorkflowActions' => $hideWorkflowActions,
            'canRequestSerialNumber' => $canRequestSerialNumber,
            'canRequestCorrectSerial' => $canRequestCorrectSerial,
            'canCustomerNotResponding' => $canCustomerNotResponding,
            'canLinkOrder' => $canLinkOrder,
            'canCorrectCustomerDetails' => $correctCustomerDetailsEligibility['allowed'],
            'canCorrectSerialNumber' => $correctSerialNumberEligibility['allowed'],
            'correctCustomerDetailsEligibility' => $correctCustomerDetailsEligibility,
            'correctSerialNumberEligibility' => $correctSerialNumberEligibility,
            'showIdentityCorrectionActions' => $user !== null,
            'hasRecommendedActions' => $hasRecommendedActions,
        ];
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    private function eligibilityPayload(?EligibilityResult $result): array
    {
        if ($result === null) {
            return [
                'allowed' => false,
                'reason' => null,
            ];
        }

        return [
            'allowed' => $result->allowed,
            'reason' => $result->reason,
        ];
    }
}
