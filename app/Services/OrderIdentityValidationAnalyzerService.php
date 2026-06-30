<?php

namespace App\Services;

use App\Data\OrderIdentityValidationAnalysis;
use App\Data\OrderIdentityValidationAnalysisBatchResult;
use App\Data\SerialValidationResult;
use App\Enums\IncidentStatus;
use App\Enums\OrderIdentityValidationFailureGroup;
use App\Enums\OrderIdentityValidationRecommendation;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialValidationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;

class OrderIdentityValidationAnalyzerService
{
    public function __construct(
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly ServiceCaseAutomationStatusService $automationStatusService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
    ) {}

    public function analyze(
        ?string $externalOrderId = null,
        bool $failedOnly = false,
        ?int $limit = null,
    ): OrderIdentityValidationAnalysisBatchResult {
        $startedAt = microtime(true);
        $failures = [];

        $query = $this->ordersQuery($externalOrderId);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $ordersScanned = 0;

        foreach ($query->cursor() as $order) {
            $ordersScanned++;

            if (! $this->shouldAnalyzeOrder($order, $failedOnly)) {
                continue;
            }

            $failures[] = $this->analyzeOrder($order);
        }

        return new OrderIdentityValidationAnalysisBatchResult(
            ordersScanned: $ordersScanned,
            failureCount: count($failures),
            failures: $failures,
            failuresByProduct: $this->groupFailuresByProduct($failures),
            failuresByValidatorRule: $this->groupFailuresByValidatorRule($failures),
            failuresByGroup: $this->groupFailuresByFailureGroup($failures),
            topInvalidSerialPatterns: $this->topInvalidSerialPatterns($failures),
            elapsedSeconds: round(microtime(true) - $startedAt, 2),
        );
    }

    /**
     * @return Builder<Order>
     */
    private function ordersQuery(?string $externalOrderId): Builder
    {
        $query = Order::query()
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->with([
                'incidents' => fn ($incidentQuery) => $incidentQuery
                    ->whereIn('status', IncidentStatus::operationallyActive())
                    ->with('assignee.roles'),
            ])
            ->orderBy('id');

        if ($externalOrderId !== null && $externalOrderId !== '') {
            $query->where('order_id', $externalOrderId);
        } else {
            $query->whereHas('incidents', function (Builder $incidentQuery): void {
                $incidentQuery->whereIn('status', IncidentStatus::operationallyActive());
            });
        }

        return $query;
    }

    private function shouldAnalyzeOrder(Order $order, bool $failedOnly): bool
    {
        if ($this->hasDuplicateSerial($order)) {
            return true;
        }

        if ($this->eligibilityService->passesValidationForOrder($order)) {
            return false;
        }

        if ($failedOnly) {
            return $this->hasExplicitValidationFailure($order);
        }

        return true;
    }

    private function analyzeOrder(Order $order): OrderIdentityValidationAnalysis
    {
        $validation = $this->resolveValidation($order);
        $duplicateSerial = $this->hasDuplicateSerial($order);
        $radiumBoxSyncLabel = $this->radiumBoxSyncLabel($order);
        $failureGroup = $this->resolveFailureGroup($order, $validation, $duplicateSerial);
        $recommendation = $this->resolveRecommendation($order, $validation, $duplicateSerial, $failureGroup);
        $primaryIncident = $this->primaryActiveIncident($order);

        return new OrderIdentityValidationAnalysis(
            internalId: $order->id,
            externalOrderId: (string) $order->order_id,
            productName: $order->product_name,
            deviceModel: $order->device_model,
            serialNumber: $order->serial_number,
            validatorClass: $this->serialValidationService->validatorClassForOrder($order),
            validationPassed: $validation->status === SerialValidationStatus::Valid,
            failureReason: $this->failureReason($order, $validation, $duplicateSerial),
            ruleFailed: $this->formatRuleFailed($validation),
            radiumBoxSyncLabel: $radiumBoxSyncLabel,
            automationStatusLabel: $primaryIncident !== null
                ? $this->automationStatusService->statusFor($primaryIncident)->label()
                : 'N/A',
            assigneeName: $primaryIncident?->assignee?->name,
            assigneeRole: $this->resolveAssigneeRole($primaryIncident),
            recommendation: $recommendation,
            failureGroup: $failureGroup,
        );
    }

    private function resolveValidation(Order $order): SerialValidationResult
    {
        return $this->serialValidationService->validateForOrder(
            (string) $order->serial_number,
            $order,
        );
    }

    private function hasExplicitValidationFailure(Order $order): bool
    {
        if ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
            return false;
        }

        $validation = $this->serialValidationService->validateForOrder(
            (string) $order->serial_number,
            $order,
        );

        return in_array($validation->status, [
            SerialValidationStatus::Invalid,
            SerialValidationStatus::Unsupported,
        ], true);
    }

    private function hasDuplicateSerial(Order $order): bool
    {
        $serial = trim((string) $order->serial_number);

        if ($serial === '') {
            return false;
        }

        return Order::query()
            ->where('serial_number', $serial)
            ->whereKeyNot($order->id)
            ->exists();
    }

    private function isRadiumBoxNotFound(Order $order): bool
    {
        if ($this->syncStore->status($order->id) !== RadiumBoxEnrichmentSyncStatus::Failed) {
            return false;
        }

        $metadata = $this->syncStore->metadata($order->id);

        return is_array($metadata)
            && ($metadata['lookup_result'] ?? null) === 'order_not_found';
    }

    private function resolveFailureGroup(
        Order $order,
        SerialValidationResult $validation,
        bool $duplicateSerial,
    ): OrderIdentityValidationFailureGroup {
        if ($duplicateSerial) {
            return OrderIdentityValidationFailureGroup::DuplicateSerial;
        }

        if ($this->isRadiumBoxNotFound($order)) {
            return OrderIdentityValidationFailureGroup::RadiumBoxNotFound;
        }

        if ($validation->status === SerialValidationStatus::Unsupported) {
            return OrderIdentityValidationFailureGroup::ProductMappingMismatch;
        }

        if ($validation->status === SerialValidationStatus::Pending) {
            return OrderIdentityValidationFailureGroup::WaitingForCustomerSerial;
        }

        if ($validation->status === SerialValidationStatus::Invalid) {
            return OrderIdentityValidationFailureGroup::ValidatorRule;
        }

        if ($this->hasProductMappingMismatch($order, $validation)) {
            return OrderIdentityValidationFailureGroup::ProductMappingMismatch;
        }

        return OrderIdentityValidationFailureGroup::Unknown;
    }

    private function hasProductMappingMismatch(Order $order, SerialValidationResult $validation): bool
    {
        if ($validation->status === SerialValidationStatus::Unsupported) {
            return true;
        }

        if ($this->serialValidationService->validatorClassForOrder($order) === null
            && (filled(trim((string) $order->product_name)) || filled(trim((string) $order->device_model)))) {
            return true;
        }

        $resolvedProduct = $this->serialValidationService->resolveProductFromOrder($order);
        $productName = filled(trim((string) $order->product_name))
            ? $this->serialValidationService->resolveProductName($order->product_name)
            : null;
        $deviceModel = filled(trim((string) $order->device_model))
            ? $this->serialValidationService->resolveProductName($order->device_model)
            : null;

        if ($productName !== null && $deviceModel !== null && $productName !== $deviceModel) {
            return true;
        }

        return $resolvedProduct !== null
            && $this->serialValidationService->validatorClassForOrder($order) === null;
    }

    private function resolveRecommendation(
        Order $order,
        SerialValidationResult $validation,
        bool $duplicateSerial,
        OrderIdentityValidationFailureGroup $failureGroup,
    ): OrderIdentityValidationRecommendation {
        if ($duplicateSerial) {
            return OrderIdentityValidationRecommendation::DuplicateSerialConflict;
        }

        if ($failureGroup === OrderIdentityValidationFailureGroup::ProductMappingMismatch) {
            return OrderIdentityValidationRecommendation::ProductMappingMismatch;
        }

        if ($validation->status === SerialValidationStatus::Pending) {
            return OrderIdentityValidationRecommendation::WaitingForCustomerSerial;
        }

        $syncStatus = $this->syncStore->status($order->id);

        if ($validation->status === SerialValidationStatus::Invalid
            && $syncStatus === RadiumBoxEnrichmentSyncStatus::Synced) {
            return OrderIdentityValidationRecommendation::RadiumBoxInvalidIdentity;
        }

        if ($validation->status === SerialValidationStatus::Invalid) {
            return OrderIdentityValidationRecommendation::ValidatorTooStrict;
        }

        return OrderIdentityValidationRecommendation::ManualReviewRequired;
    }

    private function failureReason(
        Order $order,
        SerialValidationResult $validation,
        bool $duplicateSerial,
    ): ?string {
        if ($duplicateSerial) {
            return 'This serial number belongs to a different order.';
        }

        if ($this->isRadiumBoxNotFound($order)) {
            $metadata = $this->syncStore->metadata($order->id);
            $lastError = is_array($metadata) ? ($metadata['last_error'] ?? null) : null;

            return is_string($lastError) && $lastError !== ''
                ? $lastError
                : 'Order was not found in RadiumBox.';
        }

        return $validation->reason;
    }

    private function formatRuleFailed(SerialValidationResult $validation): ?string
    {
        if ($validation->reason === null
            || $validation->status === SerialValidationStatus::Valid
            || $validation->status === SerialValidationStatus::Pending) {
            return null;
        }

        $productCode = strtoupper(str_replace(' ', '', $validation->product ?? 'UNKNOWN'));

        return sprintf('%s: %s', $productCode, $validation->reason);
    }

    private function radiumBoxSyncLabel(Order $order): string
    {
        $status = $this->syncStore->status($order->id);

        return match ($status) {
            RadiumBoxEnrichmentSyncStatus::Pending => 'Pending',
            RadiumBoxEnrichmentSyncStatus::Synced => 'Synced',
            RadiumBoxEnrichmentSyncStatus::Failed => 'Failed',
            default => 'Unknown',
        };
    }

    private function primaryActiveIncident(Order $order): ?Incident
    {
        return $order->incidents
            ->first(fn (Incident $incident): bool => $incident->status !== IncidentStatus::Closed);
    }

    private function resolveAssigneeRole(?Incident $incident): ?string
    {
        $assignee = $incident?->assignee;

        if ($assignee === null) {
            return null;
        }

        if ($assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])) {
            return RolePermissionSeeder::ROLE_ADMIN;
        }

        if ($assignee->hasRole(RolePermissionSeeder::ROLE_AGENT)) {
            return RolePermissionSeeder::ROLE_AGENT;
        }

        return $assignee->roles->first()?->name;
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return array<string, int>
     */
    private function groupFailuresByProduct(array $failures): array
    {
        $groups = [];

        foreach ($failures as $failure) {
            $product = filled(trim((string) $failure->deviceModel))
                ? trim((string) $failure->deviceModel)
                : (filled(trim((string) $failure->productName))
                    ? trim((string) $failure->productName)
                    : 'Unknown');

            $groups[$product] = ($groups[$product] ?? 0) + 1;
        }

        arsort($groups);

        return $groups;
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return array<string, int>
     */
    private function groupFailuresByValidatorRule(array $failures): array
    {
        $groups = [];

        foreach ($failures as $failure) {
            if ($failure->failureGroup !== OrderIdentityValidationFailureGroup::ValidatorRule) {
                continue;
            }

            $rule = $failure->ruleFailed ?? 'Unknown rule';

            $groups[$rule] = ($groups[$rule] ?? 0) + 1;
        }

        arsort($groups);

        return $groups;
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return array<string, int>
     */
    private function groupFailuresByFailureGroup(array $failures): array
    {
        $groups = [];

        foreach (OrderIdentityValidationFailureGroup::cases() as $group) {
            $groups[$group->label()] = 0;
        }

        foreach ($failures as $failure) {
            $label = $failure->failureGroup->label();
            $groups[$label] = ($groups[$label] ?? 0) + 1;
        }

        return array_filter($groups, fn (int $count): bool => $count > 0);
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return array<string, int>
     */
    private function topInvalidSerialPatterns(array $failures): array
    {
        $patterns = [];

        foreach ($failures as $failure) {
            if ($failure->validationPassed) {
                continue;
            }

            $serial = strtoupper(trim((string) $failure->serialNumber));

            if ($serial === '' || $this->placeholderService->isPlaceholder($failure->serialNumber)) {
                continue;
            }

            $patterns[$serial] = ($patterns[$serial] ?? 0) + 1;
        }

        arsort($patterns);

        return array_slice($patterns, 0, 20, true);
    }
}
