<?php

namespace App\Console\Commands;

use App\Models\CommerceOrder;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:authorize-recovered-fulfilment {source_id? : Frozen RDE source id} {commerce_order_no? : Existing Desk Commerce order number} {--initial-seven : Authorize only the seven recovered frozen Commerce pairs} {--purpose=owner-authorized-recovered-commerce-fulfilment : Authorization purpose} {--actor= : Operator or prompt id recorded on the row}')]
#[Description('Record Desk-local recovered-Commerce fulfilment authorization. Does not open HF or replay Box handoffs.')]
class AuthorizeRecoveredFulfilmentCommand extends Command
{
    /**
     * Exact pairs for the one-time production population. Eligibility reads table rows, not this map.
     *
     * @var array<string, string>
     */
    public const INITIAL_PRODUCTION_PAIRS = [
        'RDE318360' => 'CO-000757',
        'RDE318367' => 'CO-000761',
        'RDE318378' => 'CO-000760',
        'RDE318379' => 'CO-000756',
        'RDE318382' => 'CO-000759',
        'RDE318388' => 'CO-000755',
        'RDE318391' => 'CO-000758',
    ];

    public function handle(HardwareRecoveredFulfilmentAuthorization $authorizations): int
    {
        $purpose = trim((string) $this->option('purpose'));
        $actor = trim((string) ($this->option('actor') ?? ''));
        $actor = $actor !== '' ? $actor : 'artisan';
        $initialSeven = (bool) $this->option('initial-seven');
        $sourceId = $this->argument('source_id');
        $orderNo = $this->argument('commerce_order_no');

        $pairs = [];
        if ($initialSeven) {
            if (is_string($sourceId) && trim($sourceId) !== '') {
                $this->error('--initial-seven cannot be combined with a source id argument.');

                return self::FAILURE;
            }
            $pairs = self::INITIAL_PRODUCTION_PAIRS;
        } else {
            if (! is_string($sourceId) || trim($sourceId) === '' || ! is_string($orderNo) || trim($orderNo) === '') {
                $this->error('Supply source_id and commerce_order_no, or pass --initial-seven.');

                return self::FAILURE;
            }
            $pairs = [strtoupper(trim($sourceId)) => trim($orderNo)];
        }

        $failed = false;
        $results = [];
        foreach ($pairs as $source => $expectedNo) {
            $result = $this->authorizePair($authorizations, $source, $expectedNo, $purpose, $actor);
            $results[] = $result;
            if (! $result['ok']) {
                $failed = true;
            }
        }

        $this->line(json_encode([
            'ok' => ! $failed,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{ok: bool, source_id: string, commerce_order_no: string, code: string, message: string, authorization_id: ?int}
     */
    private function authorizePair(
        HardwareRecoveredFulfilmentAuthorization $authorizations,
        string $sourceId,
        string $orderNo,
        string $purpose,
        string $actor,
    ): array {
        $matches = CommerceOrder::query()
            ->with('items')
            ->whereRaw('UPPER(source_id) = ?', [strtoupper($sourceId)])
            ->get();

        if ($matches->count() !== 1) {
            return [
                'ok' => false,
                'source_id' => $sourceId,
                'commerce_order_no' => $orderNo,
                'code' => 'commerce_identity_mismatch',
                'message' => $matches->isEmpty()
                    ? 'Existing Desk Commerce order is required. Authorization does not create Commerce.'
                    : 'Multiple commerce orders match this source id. Authorization fails closed.',
                'authorization_id' => null,
            ];
        }

        $order = $matches->first();
        if ((string) $order->order_no !== $orderNo) {
            return [
                'ok' => false,
                'source_id' => $sourceId,
                'commerce_order_no' => $orderNo,
                'code' => 'commerce_identity_mismatch',
                'message' => 'Live Commerce order_no '.$order->order_no.' does not match expected '.$orderNo.'.',
                'authorization_id' => null,
            ];
        }

        $result = $authorizations->authorizeOne($order, $purpose, $actor);

        return [
            'ok' => $result['ok'],
            'source_id' => $sourceId,
            'commerce_order_no' => $orderNo,
            'code' => $result['code'],
            'message' => $result['message'],
            'authorization_id' => $result['authorization']?->id,
        ];
    }
}
