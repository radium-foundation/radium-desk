<?php

namespace App\Console\Commands;

use App\Models\HardwareFulfilment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareHistoricalDuplicateFulfilmentCancellationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('desk:cancel-historical-duplicate-fulfilment
    {id : hardware_fulfilment.id or source id such as RDE318338}
    {--dry-run : Validate eligibility without writing}
    {--actor= : Active Desk user id authorized to cancel}
    {--reason= : Cancellation reason recorded on invoice and fulfilment}
    {--idempotency-key= : Idempotency key for this cancellation}
    {--historical-admin-invoice= : Historical Admin invoice reference such as IND671904}')]
#[Description('Cancel a verified historical duplicate Desk hardware fulfilment without Shiprocket mutation.')]
class CancelHistoricalDuplicateHardwareFulfilmentCommand extends Command
{
    public function handle(HardwareHistoricalDuplicateFulfilmentCancellationService $service): int
    {
        $fulfilment = $this->resolveFulfilment((string) $this->argument('id'));
        if ($fulfilment === null) {
            $this->error('Hardware fulfilment not found.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        if (! $actor->can(RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_CANCEL_HISTORICAL_DUPLICATE)) {
            $this->error('Actor is not authorized for historical duplicate fulfilment cancellation.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: 'Owner-approved cancellation of duplicate Desk fulfilment after historical Admin completion.'));
        $idempotencyKey = trim((string) ($this->option('idempotency-key') ?: (
            HardwareHistoricalDuplicateFulfilmentCancellationService::DEFAULT_IDEMPOTENCY_PREFIX
            .strtoupper((string) $fulfilment->source_id)
        )));
        $historicalAdminInvoice = $this->stringOption('historical-admin-invoice');

        try {
            $service->assertEligible($fulfilment->fresh([
                'commerceOrder',
                'shipment',
                'statutoryInvoice',
                'serials.inventorySerial.product',
            ]) ?? $fulfilment);
        } catch (ValidationException $exception) {
            if ($fulfilment->fresh()?->state?->value === 'cancelled_historical_duplicate') {
                $this->line(json_encode([
                    'ok' => true,
                    'dry_run' => (bool) $this->option('dry-run'),
                    'already_cancelled' => true,
                    'fulfilment_id' => $fulfilment->id,
                    'source_id' => $fulfilment->source_id,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode([
                'ok' => true,
                'dry_run' => true,
                'fulfilment_id' => $fulfilment->id,
                'source_id' => $fulfilment->source_id,
                'invoice_number' => $fulfilment->statutoryInvoice?->invoice_number,
                'shipment_id' => $fulfilment->shipment_id,
                'allocated_serials' => $fulfilment->serials
                    ->where('status', 'allocated')
                    ->pluck('serial_number')
                    ->values()
                    ->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        try {
            $result = $service->cancel(
                $fulfilment,
                $actor,
                $reason,
                $idempotencyKey,
                $historicalAdminInvoice,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function resolveFulfilment(string $identifier): ?HardwareFulfilment
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return HardwareFulfilment::query()->find((int) $identifier);
        }

        return HardwareFulfilment::query()
            ->whereRaw('UPPER(source_id) = ?', [strtoupper($identifier)])
            ->first();
    }

    private function resolveActor(): ?User
    {
        $raw = $this->option('actor');
        if (! is_numeric($raw)) {
            $this->error('desk:cancel-historical-duplicate-fulfilment requires --actor=<active Desk user id>.');

            return null;
        }

        $actor = User::query()->find((int) $raw);
        if ($actor === null || ! $actor->is_active) {
            $this->error('Actor user was not found or is inactive.');

            return null;
        }

        return $actor;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
