<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\InventorySaleSerial;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReturnExchangeCaseIntakeService
{
    public function __construct(
        private readonly ReturnExchangeCaseSourceResolver $sourceResolver,
    ) {}

    /**
     * @param  array{
     *     source_kind: string,
     *     inventory_sale_id?: int|null,
     *     order_id?: int|null,
     *     hardware_fulfilment_id?: int|null,
     *     idempotency_key?: string|null,
     *     reason: string,
     *     requester_remarks?: string|null,
     *     lines: list<array{
     *         description: string,
     *         qty?: int,
     *         serial_number?: string|null,
     *         inventory_product_id?: int|null,
     *         inventory_sale_line_id?: int|null,
     *         notes?: string|null,
     *     }>,
     * }  $payload
     */
    public function create(User $actor, array $payload): ReturnExchangeCase
    {
        $kind = ReturnExchangeCaseSourceKind::from((string) $payload['source_kind']);
        $idempotencyKey = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
            ? (string) $payload['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = ReturnExchangeCase::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertActorMayReplayIdempotentCase($actor, $existing);

                return $existing->load(['lines', 'requester', 'branch']);
            }
        }

        $resolved = $this->sourceResolver->resolve(
            $kind,
            isset($payload['inventory_sale_id']) ? (int) $payload['inventory_sale_id'] : null,
            isset($payload['order_id']) ? (int) $payload['order_id'] : null,
            isset($payload['hardware_fulfilment_id']) ? (int) $payload['hardware_fulfilment_id'] : null,
        );

        ReturnExchangeCaseBranchAccess::assertCanCreateForBranch($actor, $resolved->branchId);

        $lines = $payload['lines'] ?? [];
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one line item.',
            ]);
        }

        if ($resolved->inventorySaleId !== null) {
            $this->assertPosLinesMatchSale($resolved->inventorySaleId, $lines);
        }

        try {
            return DB::transaction(function () use ($actor, $payload, $resolved, $idempotencyKey, $lines): ReturnExchangeCase {
                if ($idempotencyKey !== null) {
                    $locked = ReturnExchangeCase::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($locked !== null) {
                        $this->assertActorMayReplayIdempotentCase($actor, $locked);

                        return $locked->load(['lines', 'requester', 'branch']);
                    }
                }

                $case = ReturnExchangeCase::query()->create([
                    'case_no' => 'RC-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                    'source_kind' => $resolved->kind,
                    'status' => ReturnExchangeCaseStatus::Submitted,
                    'branch_id' => $resolved->branchId,
                    'inventory_sale_id' => $resolved->inventorySaleId,
                    'order_id' => $resolved->orderId,
                    'hardware_fulfilment_id' => $resolved->hardwareFulfilmentId,
                    'idempotency_key' => $idempotencyKey,
                    'reason' => (string) $payload['reason'],
                    'requester_remarks' => $payload['requester_remarks'] ?? null,
                    'requested_by' => $actor->id,
                ]);

                $case->update(['case_no' => sprintf('RC-%06d', $case->id)]);

                $lineNo = 1;
                foreach ($lines as $line) {
                    $case->lines()->create([
                        'line_no' => $lineNo,
                        'description' => (string) $line['description'],
                        'inventory_product_id' => $line['inventory_product_id'] ?? null,
                        'inventory_sale_line_id' => $line['inventory_sale_line_id'] ?? null,
                        'qty' => max(1, (int) ($line['qty'] ?? 1)),
                        'serial_number' => isset($line['serial_number']) && $line['serial_number'] !== ''
                            ? (string) $line['serial_number']
                            : null,
                        'notes' => $line['notes'] ?? null,
                    ]);
                    $lineNo++;
                }

                return $case->load(['lines', 'requester', 'branch']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey === null) {
                throw $exception;
            }

            $existing = ReturnExchangeCase::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            $this->assertActorMayReplayIdempotentCase($actor, $existing);

            return $existing->load(['lines', 'requester', 'branch']);
        }
    }

    private function assertActorMayReplayIdempotentCase(User $actor, ReturnExchangeCase $case): void
    {
        if (! ReturnExchangeCaseBranchAccess::canView($actor, $case)) {
            throw new HttpException(403, 'You cannot access this return case.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertPosLinesMatchSale(int $saleId, array $lines): void
    {
        $sale = InventorySale::query()
            ->with(['lines', 'serials.serial'])
            ->find($saleId);

        if ($sale === null) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'POS sale was not found.',
            ]);
        }

        $saleLineIds = $sale->lines->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $serialNumbersOnSale = $sale->serials
            ->map(fn (InventorySaleSerial $row): string => (string) $row->serial?->serial_number)
            ->filter()
            ->values()
            ->all();

        foreach ($lines as $index => $line) {
            if (! empty($line['inventory_sale_line_id'])) {
                $saleLineId = (int) $line['inventory_sale_line_id'];
                if (! in_array($saleLineId, $saleLineIds, true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.inventory_sale_line_id" => 'Sale line does not belong to this POS sale.',
                    ]);
                }

                $saleLine = $sale->lines->firstWhere('id', $saleLineId);
                if ($saleLine instanceof InventorySaleLine && ! empty($line['inventory_product_id'])) {
                    if ((int) $line['inventory_product_id'] !== (int) $saleLine->product_id) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.inventory_product_id" => 'Product does not match the sale line.',
                        ]);
                    }
                }
            }

            if (! empty($line['serial_number'])) {
                $serial = (string) $line['serial_number'];
                if ($serialNumbersOnSale !== [] && ! in_array($serial, $serialNumbersOnSale, true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.serial_number" => 'Serial was not assigned on this POS sale.',
                    ]);
                }
            }
        }
    }
}
