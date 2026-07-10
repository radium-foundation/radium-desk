<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Models\Incident;
use App\Models\User;
use App\Services\Inquiry\InquiryOrderLinkEligibilityService;
use App\Services\Inquiry\InquiryOrderLinkService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceLinkOrderActionService
{
    public function __construct(
        private readonly InquiryOrderLinkService $linkService,
        private readonly InquiryOrderLinkEligibilityService $eligibilityService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function link(
        Incident $incident,
        User $actor,
        array $payload,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $this->eligibilityService->canShowAction($incident, $actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        try {
            $targetOrder = $this->linkService->resolveTargetOrder((string) ($payload['order_id'] ?? ''));
            $linkedIncident = $this->linkService->linkToOrder($incident, $targetOrder, $actor);
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception, $payload);
        }

        $message = sprintf(
            'Linked %s to order %s.',
            $linkedIncident->display_reference,
            $targetOrder->order_id,
        );

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            \App\Enums\WorkspaceComponent::LinkOrder,
            $linkedIncident,
        );

        return WorkspaceActionResponseBuilder::make('link-order', $linkedIncident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, 'success')
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === \App\Enums\WorkspaceContext::Customer,
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
        $message = collect($exception->errors())->flatten()->first()
            ?? 'Unable to link this enquiry to the order.';

        return WorkspaceActionResponseBuilder::make('link-order', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->build();
    }
}
