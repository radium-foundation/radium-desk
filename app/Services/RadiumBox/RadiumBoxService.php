<?php

namespace App\Services\RadiumBox;

use App\Data\EnrichmentPersistenceResult;
use App\Models\Order;
use App\Services\OrderIdentityLifecycleService;
use App\Services\OrderIdentityProtectionService;
use App\Services\RdService\RdServiceClient;
use App\Services\RdService\RdServiceFetchResult;
use Illuminate\Support\Facades\Log;

class RadiumBoxService
{
    public const PROVIDER_RDSERVICE = 'rdservice';

    public const PROVIDER_RADIUMBOX = 'radiumbox';

    /**
     * Desk payment columns that enrichment must never write.
     *
     * @var list<string>
     */
    public const PAYMENT_COLUMNS = [
        'cashfree_payment_id',
        'payment_amount',
        'payment_method',
        'payment_date',
        'bank_reference',
        'gateway_order_id',
        'gateway_payment_id',
        'transaction_id',
    ];

    public function __construct(
        private readonly RadiumBoxClient $client,
        private readonly OrderIdentityProtectionService $identityProtection,
        private readonly OrderIdentityLifecycleService $identityLifecycle,
        private readonly RdServiceClient $rdServiceClient,
    ) {}

    public function enrichOrderForWorkspace(Order $order): Order
    {
        if (! $this->needsEnrichment($order)) {
            return $order;
        }

        if ($this->rdServiceClient->isEligible($order->order_id)) {
            $rdFetch = $this->rdServiceClient->fetch($order->order_id);

            if ($rdFetch->succeeded() && $rdFetch->enrichment !== null && $rdFetch->enrichment->hasLegacyPreviewData()) {
                $order = $this->applyEnrichment($order, $rdFetch->enrichment, 'rdservice_enrichment');
            }

            $order = $order->fresh() ?? $order;

            if (! $this->needsEnrichment($order)) {
                return $order;
            }
        }

        $enrichment = $this->client->fetchOrderEnrichment($order->order_id);

        if ($enrichment === null || ! $enrichment->hasData()) {
            return $order;
        }

        return $this->applyEnrichment($order, $enrichment);
    }

    public function needsEnrichment(Order $order): bool
    {
        return ! $order->isSerialLocked()
            || (! $order->hasDeviceModelAssigned() && ! filled($order->device_model))
            || ! filled($order->product_name)
            || ! $this->hasServiceHistory($order);
    }

    /**
     * @return array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }
     */
    public function enrichOrderFromBackgroundSync(Order $order): array
    {
        $rdOutcome = $this->enrichFromRdService($order);

        if ($rdOutcome !== null && $rdOutcome['fetch_result']->retriable) {
            return $rdOutcome;
        }

        $order = $order->fresh() ?? $order;

        if ($rdOutcome !== null && ! $this->needsEnrichment($order)) {
            return $rdOutcome;
        }

        if (! $this->needsEnrichment($order)) {
            return $rdOutcome ?? [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => new RadiumBoxOrderEnrichmentFetchResult(retriable: false),
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $adminOutcome = $this->enrichFromAdmin($order);

        if ($rdOutcome === null || ! $rdOutcome['persistence']->updated) {
            return $adminOutcome;
        }

        return $this->mergeOutcomes($rdOutcome, $adminOutcome);
    }

    /**
     * @return array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }|null
     */
    private function enrichFromRdService(Order $order): ?array
    {
        if (! $this->rdServiceClient->isEligible($order->order_id)) {
            return null;
        }

        $fetch = $this->rdServiceClient->fetch($order->order_id);

        if ($fetch->retriable) {
            Log::warning('RDService order lookup failed; will retry.', [
                'order_id' => $order->order_id,
                'error_type' => $fetch->errorType,
                'http_status' => $fetch->httpStatus,
                'message' => $fetch->errorMessage,
            ]);

            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $this->toRadiumBoxFetchResult($fetch),
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        if ($fetch->fallbackToAdmin || ! $fetch->succeeded() || $fetch->enrichment === null) {
            Log::info('RDService lookup did not enrich; falling back to Admin.', [
                'order_id' => $order->order_id,
                'error_type' => $fetch->errorType,
                'http_status' => $fetch->httpStatus,
            ]);

            return null;
        }

        if (! $fetch->enrichment->hasLegacyPreviewData()) {
            return null;
        }

        $persistence = $this->persistEnrichment($order, $fetch->enrichment, 'rdservice_enrichment');

        return [
            'applied' => $persistence->updated,
            'enrichment' => $fetch->enrichment,
            'fetch_result' => $this->toRadiumBoxFetchResult($fetch),
            'persistence' => $persistence,
        ];
    }

    /**
     * @return array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }
     */
    private function enrichFromAdmin(Order $order): array
    {
        $fetchResult = $this->client->fetchOrderEnrichmentForBackgroundSync($order->order_id);

        if ($fetchResult->errorType === 'disabled') {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        if ($fetchResult->retriable) {
            return [
                'applied' => false,
                'enrichment' => null,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $enrichment = $fetchResult->enrichment;

        if ($enrichment === null || ! $enrichment->hasData()) {
            return [
                'applied' => false,
                'enrichment' => $enrichment,
                'fetch_result' => $fetchResult,
                'persistence' => $this->emptyPersistenceResult(),
            ];
        }

        $persistence = $this->persistEnrichment($order, $enrichment);

        return [
            'applied' => $persistence->updated,
            'enrichment' => $enrichment,
            'fetch_result' => $fetchResult,
            'persistence' => $persistence,
        ];
    }

    /**
     * @param  array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }  $primary
     * @param  array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }  $secondary
     * @return array{
     *     applied: bool,
     *     enrichment: ?RadiumBoxOrderEnrichment,
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     persistence: EnrichmentPersistenceResult,
     * }
     */
    private function mergeOutcomes(array $primary, array $secondary): array
    {
        $fields = array_values(array_unique([
            ...$primary['persistence']->fieldsApplied,
            ...$secondary['persistence']->fieldsApplied,
        ]));

        $persistence = new EnrichmentPersistenceResult(
            updated: $primary['persistence']->updated || $secondary['persistence']->updated,
            fieldsApplied: $fields,
            serialApplied: $primary['persistence']->serialApplied || $secondary['persistence']->serialApplied,
            deviceModelApplied: $primary['persistence']->deviceModelApplied || $secondary['persistence']->deviceModelApplied,
            warrantyApplied: $primary['persistence']->warrantyApplied || $secondary['persistence']->warrantyApplied,
            activationYearApplied: $primary['persistence']->activationYearApplied || $secondary['persistence']->activationYearApplied,
            amcApplied: $primary['persistence']->amcApplied || $secondary['persistence']->amcApplied,
        );

        $fetchResult = $secondary['fetch_result']->retriable
            ? $secondary['fetch_result']
            : $primary['fetch_result'];

        return [
            'applied' => $persistence->updated,
            'enrichment' => $secondary['enrichment'] ?? $primary['enrichment'],
            'fetch_result' => $fetchResult,
            'persistence' => $persistence,
        ];
    }

    private function toRadiumBoxFetchResult(RdServiceFetchResult $fetch): RadiumBoxOrderEnrichmentFetchResult
    {
        return new RadiumBoxOrderEnrichmentFetchResult(
            retriable: $fetch->retriable,
            enrichment: $fetch->enrichment,
            errorMessage: $fetch->errorMessage,
            errorType: $fetch->errorType,
            httpStatus: $fetch->httpStatus,
            retryAfterSeconds: $fetch->retryAfterSeconds,
            provider: RdServiceFetchResult::PROVIDER,
        );
    }

    private function applyEnrichment(
        Order $order,
        RadiumBoxOrderEnrichment $enrichment,
        string $source = 'radiumbox_enrichment',
    ): Order {
        $persistence = $this->persistEnrichment($order, $enrichment, $source);

        if (! $persistence->updated) {
            return $order;
        }

        return $order->fresh() ?? $order;
    }

    private function persistEnrichment(
        Order $order,
        RadiumBoxOrderEnrichment $enrichment,
        string $source = 'radiumbox_enrichment',
    ): EnrichmentPersistenceResult {
        $updates = $this->buildUpdates($order, $enrichment);

        foreach (self::PAYMENT_COLUMNS as $column) {
            unset($updates[$column]);
        }

        $serialApplied = array_key_exists('serial_number', $updates);
        $deviceModelApplied = array_key_exists('device_model', $updates);
        $amcApplied = array_key_exists('amc_status', $updates) || array_key_exists('amc_details', $updates);
        $fieldsApplied = array_keys($updates);

        if ($updates === []) {
            return $this->emptyPersistenceResult();
        }

        $order->update($updates);

        $this->identityLifecycle->afterIdentityFieldsChanged(
            order: $order,
            actor: $this->identityLifecycle->resolveActorForOrder($order),
            source: $source,
            changedFields: $fieldsApplied,
        );

        return new EnrichmentPersistenceResult(
            updated: true,
            fieldsApplied: $fieldsApplied,
            serialApplied: $serialApplied,
            deviceModelApplied: $deviceModelApplied,
            warrantyApplied: false,
            activationYearApplied: false,
            amcApplied: $amcApplied,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpdates(Order $order, RadiumBoxOrderEnrichment $enrichment): array
    {
        $updates = [];

        if (! $order->isSerialLocked() && filled($enrichment->serialNumber)) {
            $serialNumber = strtoupper(trim($enrichment->serialNumber));

            if ($serialNumber !== '') {
                $existingOwner = $this->findSerialOwner($order, $serialNumber);

                if ($existingOwner !== null) {
                    Log::warning('Duplicate serial prevented.', [
                        'incoming_order_id' => $order->order_id,
                        'incoming_order_db_id' => $order->id,
                        'existing_owner_order_id' => $existingOwner->order_id,
                        'existing_owner_db_id' => $existingOwner->id,
                        'serial_number' => $serialNumber,
                    ]);
                } else {
                    $updates['serial_number'] = $serialNumber;
                }
            }
        }

        if (! $order->hasDeviceModelAssigned() && ! filled($order->device_model) && filled($enrichment->deviceModel)) {
            $updates['device_model'] = $enrichment->deviceModel;
        }

        if (! filled($order->product_name) && filled($enrichment->deviceModel)) {
            $updates['product_name'] = $enrichment->deviceModel;
        }

        if (! $this->hasServiceHistory($order) && is_array($enrichment->serviceHistory) && $enrichment->serviceHistory !== []) {
            $updates['service_history'] = $enrichment->serviceHistory;
        }

        if (! filled($order->gst_number) && filled($enrichment->gstNumber)) {
            $updates['gst_number'] = $enrichment->gstNumber;
        }

        if (! filled($order->invoice_number) && filled($enrichment->invoiceNumber)) {
            $updates['invoice_number'] = $enrichment->invoiceNumber;
        }

        if (! filled($order->purchase_year) && filled($enrichment->purchaseYear)) {
            $updates['purchase_year'] = $enrichment->purchaseYear;
        }

        $amcStatus = $enrichment->amcStatus ?? $enrichment->amc;

        if (! filled($order->amc_status) && filled($amcStatus)) {
            $updates['amc_status'] = $amcStatus;
        }

        if (! filled($order->amc_year) && filled($enrichment->amcYear)) {
            $updates['amc_year'] = $enrichment->amcYear;
        }

        if ((! is_array($order->amc_details) || $order->amc_details === []) && is_array($enrichment->amcDetails) && $enrichment->amcDetails !== []) {
            $updates['amc_details'] = $enrichment->amcDetails;
        }

        if (! filled($order->legacy_order_status) && filled($enrichment->legacyOrderStatus)) {
            $updates['legacy_order_status'] = $enrichment->legacyOrderStatus;
        }

        if ($order->legacy_order_date === null && $enrichment->legacyOrderDate !== null) {
            $updates['legacy_order_date'] = $enrichment->legacyOrderDate;
        }

        $updates = [
            ...$updates,
            ...$this->identityProtection->buildExternalIdentityUpdates($order, [
                'customer_name' => $enrichment->customerName,
                'customer_phone' => $enrichment->customerPhone,
                'customer_email' => $enrichment->customerEmail,
            ]),
        ];

        return $updates;
    }

    private function hasServiceHistory(Order $order): bool
    {
        $history = $order->service_history;

        return is_array($history) && $history !== [];
    }

    private function findSerialOwner(Order $order, string $serialNumber): ?Order
    {
        return Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->first(['id', 'order_id', 'serial_number']);
    }

    private function emptyPersistenceResult(): EnrichmentPersistenceResult
    {
        return new EnrichmentPersistenceResult(
            updated: false,
            fieldsApplied: [],
            serialApplied: false,
            deviceModelApplied: false,
            warrantyApplied: false,
            activationYearApplied: false,
            amcApplied: false,
        );
    }
}
