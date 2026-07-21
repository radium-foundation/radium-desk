<?php

namespace App\Services;

use App\Data\SerialValidationResult;
use App\Enums\WorkspaceComponent;
use App\Enums\WorkspaceContext;
use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\SerialCorrection\SerialCorrectionEligibilityService;
use App\Services\SerialValidation\SerialValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceCorrectSerialNumberActionService
{
    public function __construct(
        private readonly OrderSerialService $orderSerialService,
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialCorrectionEligibilityService $eligibilityService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Incident $incident, User $actor, string $serialNumber): array
    {
        if (! $this->eligibilityService->canShowAction($incident, $actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return $this->emptyPreview('This service case has no order.');
        }

        $normalizedInput = strtoupper(trim($serialNumber));

        if ($normalizedInput === '') {
            return $this->emptyPreview('Enter a serial number to validate.');
        }

        $validation = $this->serialValidationService->validateForOrder($normalizedInput, $order);
        $duplicate = $this->duplicateOwner($order, $validation->normalizedSerial);

        return $this->formatPreview($validation, $duplicate);
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
                    'serial_number' => 'This service case has no order.',
                ]),
            );
        }

        $serialNumber = strtoupper(trim((string) ($payload['serial_number'] ?? '')));
        $currentSerial = strtoupper(trim((string) ($order->serial_number ?? '')));

        if ($serialNumber === '') {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => 'Serial number is required.',
                ]),
            );
        }

        if ($currentSerial !== '' && $currentSerial === $serialNumber) {
            return $this->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages([
                    'serial_number' => 'Serial number was not changed.',
                ]),
            );
        }

        try {
            DB::transaction(function () use ($order, $serialNumber, $actor, $currentSerial): void {
                if ($order->isSerialLocked() && $currentSerial !== $serialNumber) {
                    $order->update([
                        'serial_number' => null,
                        'serial_entered_at' => null,
                        'serial_entered_by_user_id' => null,
                        'updated_by' => $actor->id,
                    ]);
                }

                $this->orderSerialService->assignSerialNumber(
                    order: $order->fresh(),
                    serialNumber: $serialNumber,
                    actor: $actor,
                );
            });
        } catch (ValidationException $exception) {
            return $this->validationFailure($incident, $requestContext, $exception);
        }

        $message = 'Serial number corrected successfully.';

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            WorkspaceComponent::CorrectSerialNumber,
            $incident,
        );

        return WorkspaceActionResponseBuilder::make('correct-serial-number', $incident->id)
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
            ?? 'Unable to correct serial number.';

        return WorkspaceActionResponseBuilder::make('correct-serial-number', $incident->id)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->withErrors($exception->errors())
            ->build();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPreview(SerialValidationResult $validation, ?Order $duplicate): array
    {
        return [
            'normalized_serial' => $validation->normalizedSerial,
            'severity' => $validation->severity->value,
            'status' => $validation->status->value,
            'reason' => $validation->reason,
            'corrected' => $validation->corrected,
            'duplicate' => $duplicate !== null,
            'duplicate_order_id' => $duplicate?->order_id,
            'allows_workflow' => $validation->allowsWorkflow() && $duplicate === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPreview(string $message): array
    {
        return [
            'normalized_serial' => '',
            'severity' => null,
            'status' => null,
            'reason' => $message,
            'corrected' => false,
            'duplicate' => false,
            'duplicate_order_id' => null,
            'allows_workflow' => false,
        ];
    }

    private function duplicateOwner(Order $order, string $serialNumber): ?Order
    {
        if ($serialNumber === '') {
            return null;
        }

        return Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->first();
    }
}
