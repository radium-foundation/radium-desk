<?php

namespace App\Services\Cashfree;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashfreeWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function process(CashfreeWebhookLog $webhookLog): CashfreeWebhookLog
    {
        $payload = $webhookLog->request_payload ?? [];

        $webhookLog->update([
            'cf_payment_id' => $this->payloadParser->cfPaymentId($payload),
        ]);

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $webhookLog->fresh();
        }

        try {
            return DB::transaction(function () use ($webhookLog, $payload): CashfreeWebhookLog {
                $cfPaymentId = $this->payloadParser->cfPaymentId($payload);

                if ($cfPaymentId === null) {
                    throw new RuntimeException('Cashfree webhook payload is missing cf_payment_id.');
                }

                $existingIncident = $this->findExistingIncidentForPayment($cfPaymentId);

                if ($existingIncident !== null) {
                    return $this->markProcessed($webhookLog, $existingIncident);
                }

                $order = $this->createOrder($payload, $cfPaymentId);
                $incident = $this->createServiceRequest($order, $payload);

                return $this->markProcessed($webhookLog, $incident);
            });
        } catch (\Throwable $exception) {
            $webhookLog->update([
                'processing_status' => self::STATUS_FAILED,
                'processing_error' => $exception->getMessage(),
                'processed_at' => now(),
            ]);

            return $webhookLog->fresh();
        }
    }

    private function findExistingIncidentForPayment(string $cfPaymentId): ?Incident
    {
        $existingOrder = Order::query()
            ->where('cashfree_payment_id', $cfPaymentId)
            ->first();

        if ($existingOrder !== null) {
            return $existingOrder->latestIncident();
        }

        $existingLog = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->whereNotNull('incident_id')
            ->where('processing_status', self::STATUS_PROCESSED)
            ->latest('id')
            ->first();

        if ($existingLog?->incident_id !== null) {
            return Incident::query()->find($existingLog->incident_id);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOrder(array $payload, string $cfPaymentId): Order
    {
        $orderId = $this->payloadParser->orderId($payload);

        if ($orderId === null) {
            throw new RuntimeException('Cashfree webhook payload is missing order_id.');
        }

        $systemUser = $this->resolveSystemUser();
        $paymentDate = $this->payloadParser->paymentDate($payload);

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => $this->payloadParser->customerName($payload),
            'customer_email' => $this->payloadParser->customerEmail($payload),
            'customer_phone' => $this->payloadParser->customerPhone($payload),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'cashfree_payment_id' => $cfPaymentId,
            'payment_amount' => $this->payloadParser->paymentAmount($payload),
            'payment_method' => $this->payloadParser->paymentMethod($payload),
            'payment_date' => $paymentDate !== null ? Carbon::parse($paymentDate) : null,
            'bank_reference' => $this->payloadParser->bankReference($payload),
            'gateway_order_id' => $this->payloadParser->gatewayOrderId($payload),
            'gateway_payment_id' => $this->payloadParser->gatewayPaymentId($payload),
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createServiceRequest(Order $order, array $payload): Incident
    {
        $systemUser = $this->resolveSystemUser();
        $orderId = $this->payloadParser->orderId($payload) ?? $order->order_id;

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $this->incidentReferenceService->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — '.$orderId,
            'description' => 'Automatically created from Cashfree payment webhook. Awaiting product details.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'high_priority' => false,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $incident = $this->serviceCaseAssignmentService->assignOnCreate($incident, $systemUser);
        $this->dashboardBroadcastService->serviceCaseCreated($incident, $systemUser);

        return $incident;
    }

    private function markProcessed(CashfreeWebhookLog $webhookLog, Incident $incident): CashfreeWebhookLog
    {
        $webhookLog->update([
            'incident_id' => $incident->id,
            'processing_status' => self::STATUS_PROCESSED,
            'processing_error' => null,
            'processed_at' => now(),
        ]);

        return $webhookLog->fresh(['incident']);
    }

    private function resolveSystemUser(): User
    {
        $email = (string) config('cashfree.system_user_email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->trashed() || ! $user->is_active) {
            throw new RuntimeException('Cashfree system user is not configured or inactive.');
        }

        return $user;
    }
}
