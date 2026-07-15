<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Http\Requests\Concerns\ValidatesRefundRequestPayload;
use App\Models\Incident;
use App\Models\User;
use App\Services\Concerns\BuildsWorkspaceValidationFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkspaceRefundRequestActionService
{
    use BuildsWorkspaceValidationFailure;
    use ValidatesRefundRequestPayload;

    public function __construct(
        private readonly RefundRequestService $refundRequestService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly WorkspaceRefreshRenderer $refreshRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        Incident $incident,
        User $actor,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $actor->can('refunds.create')) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'reason' => 'This service case has no linked order.',
                ]),
                $payload,
            );
        }

        $payload = self::mergeRefundRequestIncidentContext($payload, $order->id, $incident->id);
        $payload = self::mergeRefundRequestPayloadDefaults($payload);

        $validator = Validator::make(
            $payload,
            self::refundRequestValidationRules($order->id),
            [],
            self::refundRequestValidationAttributes(),
        );

        if ($validator->fails()) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                new ValidationException($validator),
                $payload,
            );
        }

        try {
            $refund = $this->refundRequestService->create(
                user: $actor,
                data: $validator->validated(),
                request: $request,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception, $payload);
        }

        $message = sprintf(
            'Refund request %s created successfully.',
            $refund->reference_no,
        );

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::RefundRequest,
            $incident,
        );

        return WorkspaceActionResponseBuilder::make('refund-request', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === WorkspaceContext::Customer,
            ])
            ->build();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validationFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        ValidationException $exception,
        array $payload = [],
    ): WorkspaceActionResponse {
        $message = $this->firstValidationMessageFromException($exception);
        $fragmentHtml = $this->refreshRenderer->renderRefundRequestFragment(
            $incident,
            $requestContext,
            $payload,
            $exception->errors(),
        );

        return WorkspaceActionResponseBuilder::make('refund-request', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->withValidationFragment('refund-request', $fragmentHtml)
            ->build();
    }
}
