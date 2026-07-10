<?php

namespace App\Services\SerialValidation;

use App\Data\OrderIdentityValidationAnalysisBatchResult;
use App\Data\SerialLearning\SerialLearningExport;
use App\Enums\SerialValidationStatus;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Services\OrderIdentityValidationAnalyzerService;
use Illuminate\Support\Facades\Config;

class SerialLearningExportService
{
    private const VALID_SERIAL_LIMIT = 500;

    public function __construct(
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly SerialInsightService $serialInsightService,
        private readonly OrderIdentityValidationAnalyzerService $validationAnalyzer,
    ) {}

    public function export(): SerialLearningExport
    {
        $failedResult = $this->validationAnalyzer->analyze(failedOnly: true);

        return new SerialLearningExport(
            exportedAt: now()->toIso8601String(),
            validSerialCount: count($this->validSerials()),
            validSerials: $this->validSerials(),
            failedValidationCount: $failedResult->failureCount,
            failedValidations: $failedValidations = $this->failedValidations($failedResult),
            correctedHistoryCount: count($this->correctedHistory()),
            correctedHistory: $this->correctedHistory(),
            productMapping: $this->productMapping(),
            validationReasons: $this->validationReasons($failedResult),
            insightAnalysis: $this->insightAnalysis($failedResult, $failedValidations),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validSerials(): array
    {
        $rows = [];

        Order::query()
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->where('order_id', 'not like', 'INQ-%')
            ->orderByDesc('serial_entered_at')
            ->orderByDesc('id')
            ->cursor()
            ->each(function (Order $order) use (&$rows): void {
                if (count($rows) >= self::VALID_SERIAL_LIMIT) {
                    return;
                }

                if ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
                    return;
                }

                $validation = $this->serialValidationService->validateForOrder(
                    (string) $order->serial_number,
                    $order,
                );

                if ($validation->status !== SerialValidationStatus::Valid) {
                    return;
                }

                $insight = $this->serialInsightService->analyze($order);

                $rows[] = [
                    'order_id' => $order->order_id,
                    'serial_number' => $validation->normalizedSerial,
                    'product' => $validation->product,
                    'device_model' => $order->device_model,
                    'corrected' => $validation->corrected,
                    'validation_reason' => $validation->reason,
                    'insight_status' => $insight->status->value,
                    'insight_confidence' => $insight->confidence->value,
                    'serial_entered_at' => $order->serial_entered_at?->toIso8601String(),
                ];
            });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedValidations(OrderIdentityValidationAnalysisBatchResult $result): array
    {
        $rows = [];

        foreach ($result->failures as $failure) {
            $order = Order::query()->find($failure->internalId);

            if ($order === null) {
                continue;
            }

            $insight = $this->serialInsightService->analyze($order);

            $rows[] = [
                'order_id' => $failure->externalOrderId,
                'serial_number' => $failure->serialNumber,
                'product_name' => $failure->productName,
                'device_model' => $failure->deviceModel,
                'validator_class' => $failure->validatorClass,
                'failure_reason' => $failure->failureReason,
                'rule_failed' => $failure->ruleFailed,
                'failure_group' => $failure->failureGroup->value,
                'recommendation' => $failure->recommendation->value,
                'radiumbox_sync' => $failure->radiumBoxSyncLabel,
                'insight_status' => $insight->status->value,
                'insight_confidence' => $insight->confidence->value,
                'insight_explanation' => $insight->explanation,
                'suggested_action' => $insight->suggestedAction,
                'technical_reason' => $insight->technicalReason,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function correctedHistory(): array
    {
        return AuditLog::query()
            ->where('event', 'serial.corrected_by_ira')
            ->where('auditable_type', Order::class)
            ->with(['auditable' => fn ($query) => $query->select(['id', 'order_id', 'device_model', 'product_name'])])
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(function (AuditLog $log): array {
                $order = $log->auditable instanceof Order ? $log->auditable : null;

                return [
                    'order_id' => $order?->order_id,
                    'original_serial' => $log->old_values['serial_number'] ?? null,
                    'corrected_serial' => $log->new_values['serial_number'] ?? null,
                    'device_model' => $order?->device_model,
                    'product_name' => $order?->product_name,
                    'corrected_at' => $log->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productMapping(): array
    {
        $validatorMapping = [];

        foreach (Config::get('serial_validation.supported_products', []) as $product => $validatorClass) {
            $validatorMapping[] = [
                'canonical_product' => $product,
                'validator_class' => is_string($validatorClass) ? $validatorClass : null,
            ];
        }

        $deviceModels = DeviceModel::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get(['id', 'name', 'code', 'brand'])
            ->map(fn (DeviceModel $model): array => [
                'id' => $model->id,
                'name' => $model->name,
                'code' => $model->code,
                'brand' => $model->brand,
            ])
            ->all();

        return [
            'ira_validators' => $validatorMapping,
            'device_models' => $deviceModels,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function validationReasons(OrderIdentityValidationAnalysisBatchResult $result): array
    {
        $reasons = [];

        foreach ($result->failures as $failure) {
            $reason = trim((string) ($failure->failureReason ?? 'unknown'));

            if ($reason === '') {
                $reason = 'unknown';
            }

            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        arsort($reasons);

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $failedValidations
     * @return array<string, mixed>
     */
    private function insightAnalysis(
        OrderIdentityValidationAnalysisBatchResult $result,
        array $failedValidations,
    ): array {
        return [
            'top_invalid_patterns' => $this->topInvalidPatterns($result),
            'product_wise_failure_reasons' => $this->productWiseFailureReasons($result),
            'confidence_tuning' => $this->confidenceTuning($failedValidations),
        ];
    }

    /**
     * @return list<array{pattern: string, count: int}>
     */
    private function topInvalidPatterns(OrderIdentityValidationAnalysisBatchResult $result): array
    {
        $patterns = [];

        foreach ($result->failures as $failure) {
            $groupKey = $failure->failureGroup->label();
            $patterns[$groupKey] = ($patterns[$groupKey] ?? 0) + 1;

            if (filled($failure->ruleFailed)) {
                $ruleKey = 'Rule: '.$failure->ruleFailed;
                $patterns[$ruleKey] = ($patterns[$ruleKey] ?? 0) + 1;
            }
        }

        arsort($patterns);

        return collect($patterns)
            ->take(15)
            ->map(fn (int $count, string $pattern): array => [
                'pattern' => $pattern,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function productWiseFailureReasons(OrderIdentityValidationAnalysisBatchResult $result): array
    {
        $grouped = [];

        foreach ($result->failures as $failure) {
            $product = trim((string) ($failure->productName ?: $failure->deviceModel ?: 'unknown'));

            if ($product === '') {
                $product = 'unknown';
            }

            $reason = trim((string) ($failure->failureReason ?? 'unknown'));

            if ($reason === '') {
                $reason = 'unknown';
            }

            $grouped[$product][$reason] = ($grouped[$product][$reason] ?? 0) + 1;
        }

        foreach ($grouped as $product => $reasons) {
            arsort($grouped[$product]);
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $failedValidations
     * @return array<string, mixed>
     */
    private function confidenceTuning(array $failedValidations): array
    {
        $distribution = [];

        foreach ($failedValidations as $row) {
            $key = ($row['insight_status'] ?? 'unknown').':'.($row['insight_confidence'] ?? 'unknown');
            $distribution[$key] = ($distribution[$key] ?? 0) + 1;
        }

        ksort($distribution);

        $total = array_sum($distribution);
        $recommendations = [];

        if ($total === 0) {
            return [
                'distribution' => $distribution,
                'recommendations' => $recommendations,
            ];
        }

        $suspiciousHigh = $distribution['suspicious:high'] ?? 0;
        $warningMedium = $distribution['warning:medium'] ?? 0;
        $warningLow = $distribution['warning:low'] ?? 0;

        if ($suspiciousHigh / $total >= 0.4) {
            $recommendations[] = 'High-confidence suspicious serials dominate failures; enable agent correct-serial outreach.';
        }

        if ($warningMedium + $warningLow > 0 && ($warningMedium + $warningLow) / $total >= 0.3) {
            $recommendations[] = 'Many warning-tier serials need manual verification before automation.';
        }

        if (($distribution['suspicious:medium'] ?? 0) / $total >= 0.25) {
            $recommendations[] = 'Medium-confidence suspicious cases benefit from SerialInsight review before customer contact.';
        }

        return [
            'distribution' => $distribution,
            'recommendations' => $recommendations,
        ];
    }
}
