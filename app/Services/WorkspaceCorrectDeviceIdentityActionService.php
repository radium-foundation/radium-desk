<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\User;
use App\Services\DeviceIdentityCorrection\DeviceIdentitySerialModelResolver;
use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceCorrectDeviceIdentityActionService
{
    public function __construct(
        private readonly OrderSerialService $orderSerialService,
        private readonly OrderDeviceModelService $orderDeviceModelService,
        private readonly DeviceIdentitySerialModelResolver $previewResolver,
        private readonly IdentityCorrectionEligibilityEvaluator $identityCorrectionEligibilityEvaluator,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Incident $incident, User $actor, int $deviceModelId, string $serialNumber): array
    {
        $this->assertAuthorized($incident, $actor);

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return $this->previewResolver->preview(
                DeviceModel::query()->make(['name' => 'Unknown']),
                $serialNumber,
            );
        }

        $deviceModel = DeviceModel::query()->find($deviceModelId);

        if ($deviceModel === null) {
            return [
                'normalized_serial' => '',
                'severity' => null,
                'status' => null,
                'reason' => 'The selected device model is invalid.',
                'corrected' => false,
                'duplicate' => false,
                'duplicate_order_id' => null,
                'allows_workflow' => false,
                'detection' => [
                    'detected_product' => null,
                    'detected_device_model_id' => null,
                    'detected_device_model_name' => null,
                    'selected_product' => null,
                    'cross_model_hint' => null,
                    'matches_selected' => false,
                ],
                'outcome' => 'validation_failed',
            ];
        }

        return $this->previewResolver->preview($deviceModel, $serialNumber, $order);
    }

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
        $this->assertAuthorized($incident, $actor);

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => 'This service case has no order.',
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

        $serialNumber = strtoupper(trim((string) ($payload['serial_number'] ?? '')));

        if ($serialNumber === '') {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => 'Serial number is required.',
                ]),
            );
        }

        $currentSerial = strtoupper(trim((string) ($order->serial_number ?? '')));
        $currentModelId = $order->device_model_id;
        $modelChanged = $currentModelId !== $deviceModel->id;
        $serialChanged = $currentSerial !== $serialNumber;

        if (! $modelChanged && ! $serialChanged) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => 'Device identity was not changed.',
                ]),
            );
        }

        $preview = $this->previewResolver->preview($deviceModel, $serialNumber, $order);
        $confirmModelSwitch = filter_var($payload['confirm_model_switch'] ?? false, FILTER_VALIDATE_BOOL);

        if ($preview['outcome'] === 'mismatch' && ! $confirmModelSwitch) {
            return $this->modelMismatchFailure($incident, $requestContext, $deviceModel, $preview);
        }

        if ($preview['outcome'] === 'validation_failed' || ! $preview['allows_workflow']) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => $preview['reason'] ?? 'Serial number could not be validated for the selected device model.',
                ]),
            );
        }

        $resolvedModel = $deviceModel;

        if ($confirmModelSwitch && filled($preview['detection']['detected_device_model_id'] ?? null)) {
            $resolvedModel = DeviceModel::query()->find((int) $preview['detection']['detected_device_model_id']) ?? $deviceModel;
        }

        try {
            DB::transaction(function () use ($order, $resolvedModel, $serialNumber, $actor, $currentSerial, $modelChanged, $serialChanged): void {
                if ($modelChanged) {
                    if ($order->hasDeviceModelAssigned()) {
                        $this->orderDeviceModelService->correctDeviceModel($order->fresh(), $resolvedModel, $actor);
                    } else {
                        $this->orderDeviceModelService->assignDeviceModel($order->fresh(), $resolvedModel, $actor);
                    }
                }

                if ($serialChanged) {
                    $freshOrder = $order->fresh();

                    if ($freshOrder->isSerialLocked() && $currentSerial !== $serialNumber) {
                        $freshOrder->update([
                            'serial_number' => null,
                            'serial_entered_at' => null,
                            'serial_entered_by_user_id' => null,
                            'updated_by' => $actor->id,
                        ]);
                    }

                    $this->orderSerialService->assignSerialNumber(
                        order: $freshOrder->fresh(),
                        serialNumber: $serialNumber,
                        actor: $actor,
                    );
                }
            });
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception);
        }

        $message = 'Device identity corrected successfully.';

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::CorrectDeviceIdentity,
            $incident,
        );

        return WorkspaceActionResponseBuilder::make('correct-device-identity', $incident->id)
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
            ?? 'Unable to correct device identity.';

        return WorkspaceActionResponseBuilder::make('correct-device-identity', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->build();
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function modelMismatchFailure(
        Incident $incident,
        WorkspaceRequestContext $requestContext,
        DeviceModel $selectedModel,
        array $preview,
    ): WorkspaceActionResponse {
        $detectedName = (string) ($preview['detection']['detected_device_model_name'] ?? 'another device model');
        $message = "The entered serial belongs to {$detectedName} instead of {$selectedModel->name}.";

        return WorkspaceActionResponseBuilder::make('correct-device-identity', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withUi(closeWorkspaceHost: false)
            ->withExtensions([
                'model_mismatch' => [
                    'detected_device_model_id' => $preview['detection']['detected_device_model_id'] ?? null,
                    'detected_device_model_name' => $detectedName,
                    'selected_device_model_id' => $selectedModel->id,
                    'selected_device_model_name' => $selectedModel->name,
                ],
            ])
            ->build();
    }

    private function assertAuthorized(Incident $incident, User $actor): void
    {
        if (! $this->identityCorrectionEligibilityEvaluator->canCorrectDeviceIdentity($incident, $actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
