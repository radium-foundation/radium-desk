<?php

namespace App\Console\Commands;

use App\Models\CommerceOrder;
use App\Services\HardwareFulfilment\HardwareOwnerAuthorizedExistingCommerceProfiles;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:authorize-existing-commerce-fulfilment {source_id : Box business order id (allowlisted only)} {commerce_order_no : Desk Commerce order number} {--purpose=owner-authorized-existing-commerce-fulfilment : Authorization purpose} {--actor= : Operator or prompt id recorded on the row}')]
#[Description('Record Owner authorization to open hardware fulfilment from existing paid Commerce. Does not open HF, allocate serials, or replay Box ingest.')]
class AuthorizeOwnerExistingCommerceFulfilmentCommand extends Command
{
    public function handle(HardwareRecoveredFulfilmentAuthorization $authorizations): int
    {
        $sourceId = strtoupper(trim((string) $this->argument('source_id')));
        $orderNo = trim((string) $this->argument('commerce_order_no'));
        $purpose = trim((string) $this->option('purpose'));
        $actor = trim((string) ($this->option('actor') ?? ''));
        $actor = $actor !== '' ? $actor : 'artisan';

        if (! HardwareOwnerAuthorizedExistingCommerceProfiles::has($sourceId)) {
            $this->error($sourceId.' is not on the Owner existing-commerce allowlist. Authorization refused.');

            return self::FAILURE;
        }

        $profile = HardwareOwnerAuthorizedExistingCommerceProfiles::profile($sourceId);
        if ($profile === null || $profile['commerce_order_no'] !== $orderNo) {
            $this->error('source_id and commerce_order_no do not match the authorized profile. Authorization refused.');

            return self::FAILURE;
        }

        $matches = CommerceOrder::query()
            ->with('items')
            ->whereRaw('UPPER(source_id) = ?', [$sourceId])
            ->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty()
                ? 'Existing Desk Commerce order is required. Authorization does not create Commerce.'
                : 'Multiple commerce orders match this source id. Authorization fails closed.');

            return self::FAILURE;
        }

        $order = $matches->first();
        if ((string) $order->order_no !== $orderNo) {
            $this->error('Live Commerce order_no '.$order->order_no.' does not match expected '.$orderNo.'.');

            return self::FAILURE;
        }

        $result = $authorizations->authorizeOne(
            $order,
            $purpose !== '' ? $purpose : HardwareOwnerAuthorizedExistingCommerceProfiles::PURPOSE,
            $actor,
        );

        $this->line(json_encode([
            'ok' => $result['ok'],
            'code' => $result['code'],
            'message' => $result['message'],
            'authorization_id' => $result['authorization']?->id,
            'source_id' => $sourceId,
            'commerce_order_no' => $orderNo,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
