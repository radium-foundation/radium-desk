<?php

namespace Tests\Concerns;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;

/**
 * Verified device serials for workspace close/resolve feature tests.
 *
 * @see \Tests\Unit\SerialValidation\SerialInsightServiceTest::mfs110CorrectSamples()
 */
trait CreatesWorkspaceServiceCaseFixtures
{
    private const VERIFIED_MFS110_SERIAL = '6419897';

    /** Product-code style serial blocked by ServiceCaseCloseRequirementService. */
    private const SUSPICIOUS_PRODUCT_CODE_SERIAL = '54SAXXC5514586';

    private function verifiedMfs110Serial(): string
    {
        return self::VERIFIED_MFS110_SERIAL;
    }

    private function suspiciousProductCodeSerial(): string
    {
        return self::SUSPICIOUS_PRODUCT_CODE_SERIAL;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createWorkspaceServiceCaseIncident(
        User $creator,
        array $overrides = [],
        string $defaultOrderId = 'ORD-WS-1',
        string $defaultTitle = 'Workspace service case test',
        string $defaultDescription = 'Workspace service case test description.',
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? $defaultOrderId,
            'serial_number' => array_key_exists('serial_number', $overrides)
                ? $overrides['serial_number']
                : $this->verifiedMfs110Serial(),
            'product_name' => $overrides['product_name'] ?? 'MFS 110',
            'device_model' => $overrides['device_model'] ?? 'MFS 110',
            'cashfree_payment_id' => $overrides['cashfree_payment_id'] ?? null,
            'transaction_id' => $overrides['transaction_id'] ?? null,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        unset(
            $overrides['order_id'],
            $overrides['serial_number'],
            $overrides['transaction_id'],
            $overrides['product_name'],
            $overrides['device_model'],
            $overrides['cashfree_payment_id'],
        );

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => $overrides['title'] ?? $defaultTitle,
            'description' => $overrides['description'] ?? $defaultDescription,
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }
}
