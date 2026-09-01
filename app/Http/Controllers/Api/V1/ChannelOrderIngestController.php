<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChannelIngestOutcome;
use App\Enums\StatutoryInvoiceChannel;
use App\Http\Controllers\Controller;
use App\Models\ChannelIngestAttempt;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\ChannelIngest\ChannelIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class ChannelOrderIngestController extends Controller
{
    public function __construct(
        private readonly ChannelIngestAuthenticator $authenticator,
        private readonly ChannelIngestService $ingest,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $auth = $this->authenticator->authenticate($request);
        if ($auth['ok'] !== true) {
            $this->recordAuthFailure($request, $auth);

            return response()->json([
                'status' => $auth['replay'] ? ChannelIngestOutcome::Replay->value : ChannelIngestOutcome::Unauthorized->value,
                'accepted' => false,
                'error' => $auth['error'],
                'invoice' => null,
            ], $auth['http_status']);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->recordAuthFailure($request, [
                'ok' => false,
                'error' => 'Request body must be JSON.',
                'replay' => false,
                'http_status' => 400,
            ], ChannelIngestOutcome::Rejected, true);

            return response()->json([
                'status' => ChannelIngestOutcome::Rejected->value,
                'accepted' => false,
                'error' => 'Request body must be JSON.',
                'invoice' => null,
            ], 400);
        }

        if (! is_array($payload)) {
            return response()->json([
                'status' => ChannelIngestOutcome::Rejected->value,
                'accepted' => false,
                'error' => 'Request body must be a JSON object.',
                'invoice' => null,
            ], 400);
        }

        try {
            $result = $this->ingest->ingest(
                payload: $payload,
                authenticatedChannel: $auth['channel'],
                remoteIp: $request->ip(),
                rawHash: hash('sha256', $request->getContent()),
                idempotencyHeader: trim((string) $request->header('Idempotency-Key', '')),
            );

            return response()->json($result->toArray(), $result->httpStatus);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => ChannelIngestOutcome::Rejected->value,
                'accepted' => false,
                'errors' => $exception->errors(),
                'invoice' => null,
            ], 422);
        }
    }

    /**
     * @param  array{ok: bool, error?: string, replay?: bool, http_status?: int}  $auth
     */
    private function recordAuthFailure(
        Request $request,
        array $auth,
        ChannelIngestOutcome $outcome = ChannelIngestOutcome::Unauthorized,
        bool $signatureOk = false,
    ): void {
        $channelHeader = trim((string) $request->header('X-Desk-Channel', ''));
        $channel = StatutoryInvoiceChannel::tryFrom($channelHeader);

        ChannelIngestAttempt::query()->create([
            'channel' => $channel,
            'source_type' => null,
            'source_id' => null,
            'idempotency_key' => null,
            'payload_hash' => hash('sha256', $request->getContent()),
            'outcome' => ($auth['replay'] ?? false) ? ChannelIngestOutcome::Replay : $outcome,
            'http_status' => $auth['http_status'] ?? 401,
            'signature_ok' => $signatureOk,
            'error' => $auth['error'] ?? ChannelIngestAuthenticator::ERROR_UNAUTHORIZED,
            'remote_ip' => $request->ip(),
            'received_at' => now(),
        ]);
    }
}
