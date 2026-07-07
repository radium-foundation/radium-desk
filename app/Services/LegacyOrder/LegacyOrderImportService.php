<?php

namespace App\Services\LegacyOrder;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Services\AuditLogService;
use App\Services\DashboardBroadcastService;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\RadiumBox\RadiumBoxClient;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyOrderImportService
{
    public function __construct(
        private readonly RadiumBoxClient $radiumBoxClient,
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
        private readonly AuditLogService $auditLogService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function import(
        User $user,
        string $orderId,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
        ?string $intakePhone = null,
    ): Incident {
        return DB::transaction(function () use ($user, $orderId, $source, $notes, $highPriority, $intakePhone): Incident {
            $existingOrder = Order::query()->where('order_id', $orderId)->first();

            if ($existingOrder !== null) {
                throw ValidationException::withMessages([
                    'legacy_order_id' => 'This order already exists in Radium Desk.',
                ]);
            }

            $enrichment = $this->radiumBoxClient->fetchOrderEnrichment($orderId);

            if ($enrichment === null || ! $enrichment->hasLegacyPreviewData()) {
                throw ValidationException::withMessages([
                    'legacy_order_id' => 'Legacy order could not be found for import.',
                ]);
            }

            $customerIdentity = $this->resolveCustomerIdentity($enrichment, $intakePhone);

            $order = Order::query()->create([
                'order_id' => $orderId,
                'customer_id' => $customerIdentity['customer_id'],
                'customer_name' => $enrichment->customerName ?? $customerIdentity['customer_name'],
                'customer_email' => $enrichment->customerEmail ?? $customerIdentity['customer_email'],
                'customer_phone' => $customerIdentity['customer_phone'],
                'serial_number' => $enrichment->serialNumber,
                'product_name' => $enrichment->deviceModel,
                'device_model' => $enrichment->deviceModel,
                'gst_number' => $enrichment->gstNumber,
                'invoice_number' => $enrichment->invoiceNumber,
                'purchase_year' => $enrichment->purchaseYear ?? $enrichment->activationYear,
                'service_history' => $enrichment->serviceHistory,
                'amc_status' => $enrichment->amcStatus ?? $enrichment->amc,
                'amc_year' => $enrichment->amcYear,
                'amc_details' => $enrichment->amcDetails,
                'legacy_order_status' => $enrichment->legacyOrderStatus ?? $enrichment->radiumboxOrderStatus,
                'legacy_source' => 'radiumbox',
                'legacy_imported_at' => now(),
                'legacy_imported_by_user_id' => $user->id,
                'status' => OrderStatus::Active,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'legacy_order.imported',
                auditable: $order,
                newValues: [
                    'order_id' => $orderId,
                    'legacy_source' => 'radiumbox',
                    'agent_name' => $user->firstName(),
                    'imported_by_user_id' => $user->id,
                ],
            );

            $product = $order->product_name ?: $order->device_model ?: 'General';

            $incident = Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $this->incidentReferenceService->generate(),
                'category' => 'General',
                'source' => $source,
                'title' => 'Legacy service request — '.$product,
                'description' => $notes ?? '',
                'status' => IncidentStatus::Open,
                'high_priority' => $highPriority,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $incident = $this->serviceCaseAssignmentService->assignWithAuditContext(
                incident: $incident,
                assignee: $user,
                actor: $user,
                auditContext: [
                    'reason' => 'legacy_order_import',
                ],
            );

            if ($highPriority && $incident->assignee !== null
                && $incident->assignee->is_active
                && ! $incident->assignee->trashed()
                && app(SettingService::class)->getBool('notifications.high_priority_enabled', true)) {
                $incident->assignee->notify(new HighPriorityServiceCaseNotification($incident, $user));
            }

            $this->dashboardBroadcastService->serviceCaseCreated($incident, $user);

            return $incident->fresh(['order', 'assignee']);
        });
    }

    /**
     * @return array{
     *     customer_phone: ?string,
     *     customer_email: ?string,
     *     customer_name: ?string,
     *     customer_id: ?string,
     * }
     */
    private function resolveCustomerIdentity(RadiumBoxOrderEnrichment $enrichment, ?string $intakePhone): array
    {
        $phone = filled($enrichment->customerPhone) ? $enrichment->customerPhone : $intakePhone;
        $normalizedPhone = null;
        $matchedOrder = null;

        if (filled($phone)) {
            $storedPhones = $this->customerMatcher->matchingStoredPhones(null, $phone);

            if ($storedPhones !== []) {
                $normalizedPhone = $storedPhones[0];
                $matchedOrder = Order::query()
                    ->where('customer_phone', $normalizedPhone)
                    ->orderByDesc('id')
                    ->first();
            } else {
                $normalizedPhone = $this->customerMatcher->resolveStoredPhone(null, $phone);
            }
        }

        if ($matchedOrder === null && filled($enrichment->customerEmail)) {
            $normalizedEmail = strtolower(trim($enrichment->customerEmail));

            $matchedOrder = Order::query()
                ->whereRaw('LOWER(TRIM(customer_email)) = ?', [$normalizedEmail])
                ->orderByDesc('id')
                ->first();
        }

        return [
            'customer_phone' => $normalizedPhone,
            'customer_email' => $matchedOrder?->customer_email,
            'customer_name' => $matchedOrder?->customer_name,
            'customer_id' => $matchedOrder?->customer_id,
        ];
    }
}
