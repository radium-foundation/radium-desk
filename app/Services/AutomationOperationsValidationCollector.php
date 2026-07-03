<?php

namespace App\Services;

use App\Data\OrderIdentityValidationAnalysis;
use App\Data\OrderIdentityValidationAnalysisBatchResult;
use App\Data\SerialValidationResult;
use App\Enums\IncidentStatus;
use App\Enums\OrderIdentityValidationFailureGroup;
use App\Enums\OrderIdentityValidationRecommendation;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Enums\SerialValidationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;

class AutomationOperationsValidationCollector
{
    public function __construct(
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly ServiceCaseAutomationStatusService $automationStatusService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
    ) {}

    /**
     * @param  array<int, ServiceCaseAutomationStatus>|null  $statusByIncidentId
     */
    public function collect(?array $statusByIncidentId = null): OrderIdentityValidationAnalysisBatchResult
    {
        $startedAt = microtime(true);
        $duplicateSerialKeys = $this->duplicateSerialKeys();
        $failures = [];
        $ordersScanned = 0;

        foreach ($this->ordersQuery()->cursor() as $order) {
            $ordersScanned++;

            if (! $this->shouldAnalyzeOrder($order, $duplicateSerialKeys)) {
                continue;
            }

            $failures[] = $this->analyzeOrder($order, $duplicateSerialKeys, $statusByIncidentId);
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
     * @return array<string, true>
     */
    private function duplicateSerialKeys(): array
    {
        $keys = [];

        Order::query()
            ->select('serial_number')
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->groupBy('serial_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('serial_number')
            ->each(function (mixed $serial) use (&$keys): void {
                $normalized = strtoupper(trim((string) $serial));

                if ($normalized !== '') {
                    $keys[$normalized] = true;
                }
            });

        return $keys;
    }

    /**
     * @return Builder<Order>
     */
    private function ordersQuery(): Builder
    {
        return Order::query()
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->with([
                'incidents' => fn ($incidentQuery) => $incidentQuery
                    ->whereIn('status', IncidentStatus::operationallyActive())
                    ->with('assignee.roles'),
            ])
            ->whereHas('incidents', function (Builder $incidentQuery): void {
                $incidentQuery->whereIn('status', IncidentStatus::operationallyActive());
            })
            ->orderBy('id');
    }

    /**
     * @param  array<string, true>  $duplicateSerialKeys
     */
    private function shouldAnalyzeOrder(Order $order, array $duplicateSerialKeys): bool
    {
        if ($this->orderHasDuplicateSerial($order, $duplicateSerialKeys)) {
            return true;
        }

        return ! $this->eligibilityService->passesValidationForOrder($order);
    }

    /**
     * @param  array<string, true>  $duplicateSerialKeys
     * @param  array<int, ServiceCaseAutomationStatus>|null  $statusByIncidentId
     */
    private function analyzeOrder(
        Order $order,
        array $duplicateSerialKeys,
        ?array $statusByIncidentId,
    ): OrderIdentityValidationAnalysis {
        $validation = $this->resolveValidation($order);
        $duplicateSerial = $this->orderHasDuplicateSerial($order, $duplicateSerialKeys);
        $primaryIncident = $this->primaryActiveIncident($order);
        $failureGroup = $this->resolveFailureGroup($order, $validation, $duplicateSerial);

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
            radiumBoxSyncLabel: $this->radiumBoxSyncLabel($order),
            automationStatusLabel: $this->automationStatusLabel($primaryIncident, $statusByIncidentId),
            assigneeName: $primaryIncident?->assignee?->name,
            assigneeRole: $this->resolveAssigneeRole($primaryIncident),
            recommendation: $this->resolveRecommendation(
                $order,
                $validation,
                $duplicateSerial,
                $failureGroup,
            ),
            failureGroup: $failureGroup,
        );
    }

    /**
     * @param  array<string, true>  $duplicateSerialKeys
     */
    private function orderHasDuplicateSerial(Order $order, array $duplicateSerialKeys): bool
    {
        $serial = strtoupper(trim((string) $order->serial_number));

        return $serial !== '' && isset($duplicateSerialKeys[$serial]);
    }

    private function resolveValidation(Order $order): SerialValidationResult
    {
        return $this->serialValidationService->validateForOrder(
            (string) $order->serial_number,
            $order,
        );
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
            RadiumBoxEnrichmentSyncStatus::NotSynced => 'Not Synced',
            RadiumBoxEnrichmentSyncStatus::Pending => 'Pending',
            RadiumBoxEnrichmentSyncStatus::Synced => 'Synced',
            RadiumBoxEnrichmentSyncStatus::Failed => 'Failed',
        };
    }

    private function primaryActiveIncident(Order $order): ?Incident
    {
        return $order->incidents
            ->first(fn (Incident $incident): bool => $incident->status !== IncidentStatus::Closed);
    }

    /**
     * @param  array<int, ServiceCaseAutomationStatus>|null  $statusByIncidentId
     */
    private function automationStatusLabel(?Incident $primaryIncident, ?array $statusByIncidentId): string
    {
        if ($primaryIncident === null) {
            return 'N/A';
        }

        if ($statusByIncidentId !== null && isset($statusByIncidentId[$primaryIncident->id])) {
            return $statusByIncidentId[$primaryIncident->id]->label();
        }

        return $this->automationStatusService->statusFor($primaryIncident)->label();
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
