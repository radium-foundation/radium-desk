<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\User;
use App\Services\DeviceModelCorrection\DeviceModelCorrectionEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceCorrectDeviceModelActionService
{
    public function __construct(
        private readonly OrderDeviceModelService $orderDeviceModelService,
        private readonly DeviceModelCorrectionEligibilityService $eligibilityService,
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
                    'device_model_id' => 'This service case has no order.',
                ]),
            );
        }

        $deviceModel = DeviceModel::query()->find((int) ($payload['device_model_id'] ?? 0));

        if ($deviceModel === null) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'device_model_id' => 'The selected device model is invalid.',
                ]),
            );
        }

        try {
            DB::transaction(function () use ($order, $deviceModel, $actor): void {
                $this->orderDeviceModelService->correctDeviceModel($order, $deviceModel, $actor);
            });
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception);
        }

        $message = 'Device model corrected successfully.';

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::CorrectDeviceModel,
            $incident,
        );

        return WorkspaceActionResponseBuilder::make('correct-device-model', $incident->id)
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
            ?? 'Unable to correct device model.';

        return WorkspaceActionResponseBuilder::make('correct-device-model', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->build();
    }
}
