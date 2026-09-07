<?php

namespace Tests\Feature\HardwareFulfilment\Support;

use App\Contracts\HardwareFulfilment\BoxFulfilmentCallbackGateway;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackRequest;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackResult;
use Illuminate\Support\Facades\DB;

/**
 * In-memory Box callback adapter. No HTTP.
 *
 * Modes: accepted, retryable_5xx, non_retryable_4xx, timeout.
 */
final class FakeBoxFulfilmentCallbackGateway implements BoxFulfilmentCallbackGateway
{
    public int $sends = 0;

    public int $transactionLevelAtLastSend = -1;

    public string $mode = 'accepted';

    /**
     * @var list<HardwareFulfilmentCallbackRequest>
     */
    public array $calls = [];

    public function provider(): string
    {
        return 'test';
    }

    public function send(HardwareFulfilmentCallbackRequest $request): HardwareFulfilmentCallbackResult
    {
        $this->sends++;
        $this->transactionLevelAtLastSend = DB::transactionLevel();
        $this->calls[] = $request;

        return match ($this->mode) {
            'retryable_5xx' => new HardwareFulfilmentCallbackResult(
                status: 'retryable',
                accepted: false,
                retryable: true,
                httpStatus: 503,
                error: 'provider 503',
            ),
            'non_retryable_4xx' => new HardwareFulfilmentCallbackResult(
                status: 'rejected',
                accepted: false,
                retryable: false,
                httpStatus: 400,
                error: 'provider 400',
            ),
            'timeout' => new HardwareFulfilmentCallbackResult(
                status: 'timeout',
                accepted: false,
                retryable: true,
                error: 'timeout after possible accept',
            ),
            default => new HardwareFulfilmentCallbackResult(
                status: 'accepted',
                accepted: true,
                retryable: false,
                httpStatus: 200,
            ),
        };
    }
}
