<?php

namespace App\Console\Commands;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Models\HardwareFulfilment;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

#[Signature('desk:shiprocket-provider-courier-reassign
    {fulfilment : Hardware fulfilment id (allowlist only)}
    {--courier-id= : Target Shiprocket courier id; defaults to shipment courier}
    {--execute : Submit provider reassignment POST (default is dry-run preflight only)}
    {--force : Skip interactive confirmation with --execute}
    {--json : Emit machine-readable JSON only}')]
#[Description('P-20-47: controlled Shiprocket courier reassignment (status reassign) for allowlisted hardware fulfilments.')]
class ShiprocketProviderCourierReassignCommand extends Command
{
    public function handle(
        HardwareShipmentService $shipments,
        ShiprocketGateway $gateway,
    ): int {
        $fulfilmentId = (int) $this->argument('fulfilment');
        $allowlist = array_map('intval', config('hardware_shiprocket_provider_recovery.courier_reassign_fulfilment_ids', []));

        if (! in_array($fulfilmentId, $allowlist, true)) {
            $this->error('Fulfilment '.$fulfilmentId.' is not on the P-20-47 courier reassignment allowlist.');

            return self::FAILURE;
        }

        if ($gateway instanceof NullShiprocketGateway) {
            $this->error('Shiprocket provider is not configured for HTTP on this environment.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        if ($execute && ! (bool) $this->option('force')) {
            if (! $this->confirm('Execute Shiprocket courier reassignment POST for fulfilment '.$fulfilmentId.'?', false)) {
                $this->warn('Cancelled.');

                return self::SUCCESS;
            }
        }

        /** @var HardwareFulfilment $fulfilment */
        $fulfilment = HardwareFulfilment::query()->with('shipment')->findOrFail($fulfilmentId);

        $walletSnapshot = $this->walletSnapshot($gateway);

        try {
            $outcome = $shipments->reassignCourierViaProviderApi(
                $fulfilment,
                courierId: $this->option('courier-id'),
                executeProviderPost: $execute,
                actor: Auth::user(),
                walletSnapshot: $walletSnapshot,
            );
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['shipping'] ?? ['Courier reassignment failed.']);
            $payload = [
                'fulfilment_id' => $fulfilmentId,
                'executed' => $execute,
                'error' => $message,
                'wallet' => $walletSnapshot,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $pickupError = ShiprocketTrackingNormalizer::pickupErrorOnTrack($outcome->preflightTrack);

        $payload = [
            'fulfilment_id' => $fulfilmentId,
            'shipment_id' => $outcome->shipment->id,
            'external_shipment_id' => $outcome->shipment->external_shipment_id,
            'executed' => $execute,
            'posted_to_provider' => $outcome->postedToProvider,
            'desk_updated' => $outcome->deskUpdated,
            'already_posted' => $outcome->alreadyPosted,
            'previous_awb' => $outcome->previousAwb,
            'new_awb' => $outcome->newAwb,
            'provider_status' => $outcome->providerResult->status,
            'provider_error' => $outcome->providerResult->error,
            'preflight_pickup_error' => $pickupError,
            'post_track_status' => $outcome->postAssignTrack?->status,
            'post_pickup_error' => $outcome->postAssignTrack !== null
                ? ShiprocketTrackingNormalizer::pickupErrorOnTrack($outcome->postAssignTrack)
                : null,
            'wallet' => $walletSnapshot,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($execute ? 'Courier reassignment executed.' : 'Dry-run preflight complete.');
            $this->line('Pickup error before: '.($pickupError ? 'yes' : 'no'));
            if ($outcome->postedToProvider) {
                $this->line('Provider status: '.$outcome->providerResult->status);
                $this->line('AWB: '.$outcome->previousAwb.' -> '.($outcome->newAwb ?? '?'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function walletSnapshot(ShiprocketGateway $gateway): array
    {
        try {
            $wallet = $gateway->getWalletBalance();

            return [
                'status' => $wallet->status,
                'balance_amount' => $wallet->balanceAmount,
                'error' => $wallet->error,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'error' => $exception->getMessage(),
            ];
        }
    }
}
