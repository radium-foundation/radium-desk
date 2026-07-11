<?php

namespace App\Services;

use App\Data\CustomerCorrectionData;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Models\Incident;
use App\Models\User;
use App\Services\CustomerCorrection\CustomerCorrectionEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceCorrectCustomerDetailsActionService
{
    public function __construct(
        private readonly CustomerCorrectionService $correctionService,
        private readonly CustomerCorrectionEligibilityService $eligibilityService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function correct(
        Incident $incident,
        User $actor,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $this->eligibilityService->canShowAction($incident, $actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'customer_name' => 'This service case has no order.',
                ]),
            );
        }

        try {
            $this->correctionService->apply(
                $order,
                new CustomerCorrectionData(
                    customerName: $this->nullableString($payload['customer_name'] ?? null),
                    customerPhone: $this->nullableString($payload['customer_phone'] ?? null),
                    customerEmail: $this->nullableString($payload['customer_email'] ?? null),
                    reason: trim((string) ($payload['reason'] ?? '')),
                ),
                $actor,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception);
        }

        $message = 'Customer details updated successfully.';

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::CorrectCustomerDetails,
            $incident,
        );

        return WorkspaceActionResponseBuilder::make('correct-customer-details', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === WorkspaceContext::Customer,
            ])
            ->build();
    }

    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
    ): WorkspaceActionResponse {
        $message = collect($exception->errors())->flatten()->first()
            ?? 'Unable to update customer details.';

        return WorkspaceActionResponseBuilder::make('correct-customer-details', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->build();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
