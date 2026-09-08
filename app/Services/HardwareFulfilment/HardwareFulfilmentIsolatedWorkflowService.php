<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\ChannelIngestOutcome;
use App\Enums\HardwareFulfilmentState;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestPayloadHasher;
use App\Services\ChannelIngest\ChannelIngestPayloadValidator;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\Shipping\HttpShiprocketGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentIsolatedWorkflowService
{
    public const STEP_STATUS = 'status';

    public const STEP_INGEST = 'ingest';

    public const STEP_READY = 'ready';

    public const STEP_ALLOCATE = 'allocate';

    public const STEP_INVOICE = 'invoice';

    public const STEP_SHIP = 'ship';

    public const STEP_AWB = 'awb';

    public const STEP_SHIPPED = 'shipped';

    public const STEP_SYNC = 'sync';

    /**
     * @var list<string>
     */
    public const MUTATING_STEPS = [
        self::STEP_INGEST,
        self::STEP_READY,
        self::STEP_ALLOCATE,
        self::STEP_INVOICE,
        self::STEP_SHIP,
        self::STEP_AWB,
        self::STEP_SHIPPED,
        self::STEP_SYNC,
    ];

    /**
     * @var array<string, int>
     */
    private const THROUGH_RANK = [
        self::STEP_INGEST => 1,
        self::STEP_READY => 2,
        self::STEP_ALLOCATE => 3,
        self::STEP_INVOICE => 4,
        self::STEP_SHIP => 5,
        self::STEP_AWB => 6,
        self::STEP_SHIPPED => 7,
        self::STEP_SYNC => 8,
    ];

    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwareSerialAllocationService $serials,
        private readonly HardwareFulfilmentInvoiceService $invoices,
        private readonly HardwareFulfilmentCallbackProcessor $callbacks,
        private readonly ChannelIngestService $ingest,
        private readonly ChannelIngestPayloadValidator $payloads,
        private readonly ChannelIngestPayloadHasher $hasher,
        private readonly HardwareHandoffTenderContract $tenders,
    ) {}

    /**
     * @param  list<string>  $serials
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function run(
        string $identifier,
        string $step = self::STEP_STATUS,
        bool $dryRun = false,
        ?string $through = null,
        array $serials = [],
        ?string $claimedBranch = null,
        ?array $payload = null,
        ?User $actor = null,
        bool $liveShipping = false,
        bool $forceCallback = false,
    ): array {
        $id = HardwareFulfilmentEligibility::assertSingularIdentifier($identifier);
        $step = strtolower(trim($step));
        $through = $through !== null ? strtolower(trim($through)) : null;

        if ($through !== null && $through !== '') {
            if (! isset(self::THROUGH_RANK[$through])) {
                throw ValidationException::withMessages([
                    'through' => 'Unknown isolated fulfilment target step.',
                ]);
            }
            $step = 'through';
        } elseif (! in_array($step, [self::STEP_STATUS, ...self::MUTATING_STEPS], true)) {
            throw ValidationException::withMessages([
                'step' => 'Unknown isolated fulfilment step.',
            ]);
        }

        if ($liveShipping) {
            $this->bindLiveShipping();
        }

        if ($dryRun) {
            return $this->dryRun($id, $step === 'through' ? $through : $step, $payload);
        }

        if ($step === 'through') {
            return $this->runThrough($id, $through, $serials, $claimedBranch, $payload, $actor, $forceCallback);
        }

        return match ($step) {
            self::STEP_STATUS => $this->status($id),
            self::STEP_INGEST => $this->ingestOne($id, $payload),
            self::STEP_READY => $this->ready($id, $actor),
            self::STEP_ALLOCATE => $this->allocate($id, $serials, $actor, $claimedBranch),
            self::STEP_INVOICE => $this->invoice($id, $actor),
            self::STEP_SHIP => $this->ship($id, $actor),
            self::STEP_AWB => $this->awb($id, $actor),
            self::STEP_SHIPPED => $this->shipped($id, $actor),
            self::STEP_SYNC => $this->sync($id, $forceCallback),
            default => throw ValidationException::withMessages([
                'step' => 'Unknown isolated fulfilment step.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function dryRun(string $id, string $planned, ?array $payload): array
    {
        $fulfilment = $this->findExisting($id);
        if ($planned === self::STEP_INGEST || ($fulfilment === null && $payload !== null)) {
            $this->assertIngestPayload($id, $payload ?? []);

            return [
                'ok' => true,
                'dry_run' => true,
                'identifier' => $id,
                'planned' => self::STEP_INGEST,
                'state' => null,
                'message' => 'Would ingest exactly one verified handoff payload. No commerce order was created.',
            ];
        }

        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'id' => 'No hardware fulfilment exists for this identifier. Isolated ingest requires --payload and --step=ingest.',
            ]);
        }

        $order = $this->requireOrder($fulfilment);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $order);

        return [
            'ok' => true,
            'dry_run' => true,
            'identifier' => $id,
            'planned' => $planned,
            'state' => $fulfilment->state->value,
            'commerce_order_id' => $fulfilment->commerce_order_id,
            'source_id' => $fulfilment->source_id,
            'message' => 'No writes were performed.',
        ];
    }

    /**
     * @param  list<string>  $serials
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function runThrough(
        string $id,
        string $target,
        array $serials,
        ?string $claimedBranch,
        ?array $payload,
        ?User $actor,
        bool $forceCallback,
    ): array {
        $steps = [];
        $targetRank = self::THROUGH_RANK[$target];
        $fulfilment = $this->findExisting($id);

        if ($fulfilment === null) {
            if ($targetRank < self::THROUGH_RANK[self::STEP_INGEST]) {
                throw ValidationException::withMessages([
                    'id' => 'No hardware fulfilment exists for this identifier.',
                ]);
            }
            $steps[] = $this->ingestOne($id, $payload);
            $fulfilment = $this->requireExisting($id);
        }

        $order = $this->requireOrder($fulfilment);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $order);
        $this->assertNotAlreadyCompleted($fulfilment, $target);

        if ($targetRank >= self::THROUGH_RANK[self::STEP_READY]
            && $fulfilment->state === HardwareFulfilmentState::Ingested) {
            $steps[] = $this->ready($id, $actor);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_ALLOCATE]
            && $fulfilment->state === HardwareFulfilmentState::ReadyForFulfilment) {
            $steps[] = $this->allocate($id, $serials, $actor, $claimedBranch);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_INVOICE]
            && $fulfilment->state === HardwareFulfilmentState::SerialsAllocated) {
            $steps[] = $this->invoice($id, $actor);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_SHIP]
            && $fulfilment->state === HardwareFulfilmentState::InvoiceIssued) {
            $steps[] = $this->ship($id, $actor);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_AWB]
            && $fulfilment->state === HardwareFulfilmentState::ShipmentCreated) {
            $steps[] = $this->awb($id, $actor);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_SHIPPED]
            && $fulfilment->state === HardwareFulfilmentState::AwbAssigned) {
            $steps[] = $this->shipped($id, $actor);
            $fulfilment = $this->requireExisting($id);
        }

        if ($targetRank >= self::THROUGH_RANK[self::STEP_SYNC]
            && $fulfilment->state === HardwareFulfilmentState::Shipped) {
            $steps[] = $this->sync($id, $forceCallback);
            $fulfilment = $this->requireExisting($id);
        }

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => 'through',
            'through' => $target,
            'state' => $fulfilment->state->value,
            'steps' => $steps,
            'report' => $this->report($fulfilment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function status(string $id): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_STATUS,
            'state' => $fulfilment->state->value,
            'report' => $this->report($fulfilment),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function ingestOne(string $id, ?array $payload): array
    {
        $existing = $this->findExisting($id);
        if ($existing !== null) {
            // Isolated ingest is keyed by source id. A second run is duplicate and
            // does not re-hash the payload. Changed tenders conflict on HTTP ingest.
            HardwareFulfilmentEligibility::assertIsolatedTarget($existing, $this->requireOrder($existing));

            return [
                'ok' => true,
                'identifier' => $id,
                'step' => self::STEP_INGEST,
                'state' => $existing->state->value,
                'duplicate' => true,
                'report' => $this->report($existing),
            ];
        }

        $request = $this->assertIngestPayload($id, $payload ?? []);
        $result = $this->ingest->ingest(
            payload: $payload ?? [],
            authenticatedChannel: StatutoryInvoiceChannel::RadiumBoxCom,
        );

        if (! in_array($result->outcome, [ChannelIngestOutcome::Accepted, ChannelIngestOutcome::Duplicate], true)
            || $result->order === null) {
            throw ValidationException::withMessages([
                'ingest' => $result->error ?? 'Isolated ingest refused the selected handoff payload.',
            ]);
        }

        $order = $result->order->fresh(['items']) ?? $result->order;
        if (! $this->hasher->matchesStored((string) $order->payload_hash, $request)) {
            throw ValidationException::withMessages([
                'ingest' => 'Persisted handoff payload hash does not match the selected payload.',
            ]);
        }

        $fulfilment = HardwareFulfilment::query()->where('commerce_order_id', $order->id)->first();
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'ingest' => 'Ingest persisted a commerce order but did not open a hardware fulfilment.',
            ]);
        }

        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $order);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_INGEST,
            'state' => $fulfilment->state->value,
            'duplicate' => $result->duplicate,
            'report' => $this->report($fulfilment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ready(string $id, ?User $actor): array
    {
        $fulfilment = $this->requireExisting($id);
        $updated = $this->workflow->markReady(
            $fulfilment,
            actorType: $actor !== null ? 'user' : 'system',
            actorId: $actor?->id,
        );

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_READY,
            'state' => $updated->state->value,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @param  list<string>  $serials
     * @return array<string, mixed>
     */
    private function allocate(string $id, array $serials, ?User $actor, ?string $claimedBranch): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));
        $this->assertNotAlreadyCompleted($fulfilment, self::STEP_ALLOCATE);

        if ($actor === null) {
            throw ValidationException::withMessages([
                'actor' => 'Serial allocation requires --actor with an active Desk user id.',
            ]);
        }

        $numbers = array_values(array_filter(array_map(
            static fn (string $serial): string => strtoupper(trim($serial)),
            $serials,
        )));
        if ($numbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'Isolated allocation requires explicit --serials. Stock is not auto-picked.',
            ]);
        }

        $updated = $this->serials->allocateSerials($fulfilment, $numbers, $actor, $claimedBranch);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_ALLOCATE,
            'state' => $updated->state->value,
            'serials' => $this->workflow->allocatedSerialNumbers($updated),
            'report' => $this->report($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(string $id, ?User $actor): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));
        $this->assertNotAlreadyCompleted($fulfilment, self::STEP_INVOICE);

        $invoice = $this->invoices->issueInvoice($fulfilment, $actor);
        $updated = $this->requireExisting($id);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_INVOICE,
            'state' => $updated->state->value,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ship(string $id, ?User $actor): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));
        $this->assertNotAlreadyCompleted($fulfilment, self::STEP_SHIP);

        $shipment = $this->shipmentService()->createShipment($fulfilment, $actor);
        $updated = $this->requireExisting($id);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_SHIP,
            'state' => $updated->state->value,
            'shipment_no' => $shipment->shipment_no,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function awb(string $id, ?User $actor): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));
        $this->assertNotAlreadyCompleted($fulfilment, self::STEP_AWB);

        $shipment = $this->shipmentService()->assignAwb($fulfilment, $actor);
        $updated = $this->requireExisting($id);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_AWB,
            'state' => $updated->state->value,
            'awb' => $shipment->awb,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipped(string $id, ?User $actor): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));
        $this->assertNotAlreadyCompleted($fulfilment, self::STEP_SHIPPED);

        $updated = $this->workflow->markShipped(
            $fulfilment,
            actorType: $actor !== null ? 'user' : 'system',
            actorId: $actor?->id,
        );

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_SHIPPED,
            'state' => $updated->state->value,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(string $id, bool $forceCallback): array
    {
        $fulfilment = $this->requireExisting($id);
        HardwareFulfilmentEligibility::assertIsolatedTarget($fulfilment, $this->requireOrder($fulfilment));

        if ($fulfilment->state === HardwareFulfilmentState::Synced) {
            return [
                'ok' => true,
                'identifier' => $id,
                'step' => self::STEP_SYNC,
                'state' => $fulfilment->state->value,
                'report' => $this->report($fulfilment),
            ];
        }

        if ($fulfilment->state !== HardwareFulfilmentState::Shipped) {
            throw ValidationException::withMessages([
                'state' => 'Isolated Box callback requires SHIPPED.',
            ]);
        }

        if (! $forceCallback) {
            throw ValidationException::withMessages([
                'callback' => 'Isolated Box callback requires --force-callback. Global callback delivery stays off.',
            ]);
        }

        $events = OutboxEvent::query()
            ->where('event_type', HardwareFulfilmentCallbackOutboxWriter::EVENT_TYPE)
            ->where('aggregate_type', HardwareFulfilmentCallbackOutboxWriter::AGGREGATE_TYPE)
            ->where('aggregate_id', $fulfilment->id)
            ->whereIn('status', [OutboxEventStatus::Pending, OutboxEventStatus::Failed])
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            throw ValidationException::withMessages([
                'callback' => 'No pending isolated Box callback exists for this fulfilment.',
            ]);
        }

        foreach ($events as $event) {
            $this->callbacks->processIsolated($event);
            $event->forceFill([
                'status' => OutboxEventStatus::Completed,
                'available_at' => now(),
            ])->save();
        }

        $updated = $this->requireExisting($id);

        return [
            'ok' => true,
            'identifier' => $id,
            'step' => self::STEP_SYNC,
            'state' => $updated->state->value,
            'report' => $this->report($updated),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertIngestPayload(string $id, array $payload): ChannelOrderIngestRequest
    {
        if ($payload === []) {
            throw ValidationException::withMessages([
                'payload' => 'Isolated ingest requires --payload with exactly one verified handoff JSON object. Desk does not discover Box handoffs.',
            ]);
        }

        $request = $this->payloads->validate($payload, StatutoryInvoiceChannel::RadiumBoxCom);
        $this->tenders->assertExistingCashfree($request);
        $sourceId = HardwareFulfilmentEligibility::assertSingularIdentifier($request->sourceId);

        if (strcasecmp($sourceId, $id) !== 0) {
            throw ValidationException::withMessages([
                'payload' => 'Payload source_id must equal the command identifier. Other handoffs are not ingested.',
            ]);
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)
            || HardwareFulfilmentEligibility::metadataShowsHold($request->metadata)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This source id is not eligible for isolated ingest.',
            ]);
        }

        if ($request->paymentStatus !== 'paid') {
            throw ValidationException::withMessages([
                'payment' => 'Isolated ingest requires a paid handoff payload.',
            ]);
        }

        if (! HardwareFulfilmentEligibility::shouldOpenRecord($request)) {
            throw ValidationException::withMessages([
                'hardware' => 'Isolated ingest requires a verified hardware handoff.',
            ]);
        }

        if ($request->orderedAt === null || trim($request->orderedAt) === '') {
            throw ValidationException::withMessages([
                'orderdate' => 'Isolated ingest requires business ordered_at on or after 2026-09-05 00:00:00 IST.',
            ]);
        }

        $orderedAt = Carbon::parse($request->orderedAt);
        if (! HardwareFulfilmentEligibility::isOnOrAfterCutoff($orderedAt)) {
            throw ValidationException::withMessages([
                'orderdate' => 'Isolated ingest accepts business orderdate on or after 2026-09-05 00:00:00 IST only.',
            ]);
        }

        return $request;
    }

    private function findExisting(string $id): ?HardwareFulfilment
    {
        if (HardwareFulfilmentEligibility::looksLikeHardwareSourceId($id)) {
            $matches = HardwareFulfilment::query()
                ->whereRaw('UPPER(source_id) = ?', [strtoupper($id)])
                ->get();
            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'id' => 'Multiple hardware fulfilments match this source id. Isolated fulfilment fails closed.',
                ]);
            }

            return $matches->first();
        }

        if (ctype_digit($id)) {
            return HardwareFulfilment::query()->find((int) $id);
        }

        throw ValidationException::withMessages([
            'id' => 'Handoff ids are not resolved on Desk. Use the RDE source id or hardware_fulfilment.id.',
        ]);
    }

    private function requireExisting(string $id): HardwareFulfilment
    {
        $fulfilment = $this->findExisting($id);
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'id' => 'No hardware fulfilment exists for this identifier.',
            ]);
        }

        return $fulfilment->fresh(['commerceOrder.items', 'shipment', 'serials']) ?? $fulfilment;
    }

    private function requireOrder(HardwareFulfilment $fulfilment): CommerceOrder
    {
        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        return $order->loadMissing('items');
    }

    private function assertNotAlreadyCompleted(HardwareFulfilment $fulfilment, string $target): void
    {
        $terminal = in_array($fulfilment->state, [
            HardwareFulfilmentState::Shipped,
            HardwareFulfilmentState::Synced,
        ], true);

        if ($terminal && ! in_array($target, [self::STEP_STATUS, self::STEP_SYNC, 'synced'], true)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This hardware fulfilment is already shipped or synced.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function report(HardwareFulfilment $fulfilment): array
    {
        $invoice = $fulfilment->statutory_invoice_id !== null
            ? StatutoryInvoice::query()->find($fulfilment->statutory_invoice_id)
            : null;

        return [
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_id' => $fulfilment->commerce_order_id,
            'source_id' => $fulfilment->source_id,
            'state' => $fulfilment->state->value,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_count' => $invoice === null ? 0 : 1,
            'serials' => $this->workflow->allocatedSerialNumbers($fulfilment),
            'awb' => $fulfilment->awb,
            'shipment_no' => $fulfilment->shipment_no,
        ];
    }

    public static function prepareLiveShipping(): void
    {
        $email = trim((string) config('shipping.api_email'));
        $password = trim((string) config('shipping.api_password'));
        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Isolated live shipping requires Shiprocket API credentials. None were invented.',
            ]);
        }

        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'shiprocket',
        ]);

        App::instance(ShiprocketGateway::class, App::make(HttpShiprocketGateway::class));
    }

    private function bindLiveShipping(): void
    {
        self::prepareLiveShipping();
    }

    private function shipmentService(): HardwareShipmentService
    {
        return App::make(HardwareShipmentService::class);
    }
}
