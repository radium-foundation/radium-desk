<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChannelIngestOutcome;
use App\Enums\StatutoryInvoiceChannel;
use App\Http\Controllers\Controller;
use App\Models\ChannelIngestAttempt;
use App\Models\CommerceOrder;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\ChannelIngest\ChannelOrderDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class ChannelOrderIngestController extends Controller
{
    public function __construct(
        private readonly ChannelIngestAuthenticator $authenticator,
        private readonly ChannelIngestService $ingest,
        private readonly ChannelOrderDocumentService $documents,
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

    public function show(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $auth = $this->authenticator->authenticate($request);
        if ($auth['ok'] !== true) {
            return response()->json([
                'status' => $auth['replay'] ? ChannelIngestOutcome::Replay->value : ChannelIngestOutcome::Unauthorized->value,
                'error' => $auth['error'],
            ], $auth['http_status']);
        }

        $order = CommerceOrder::query()
            ->where('channel', $auth['channel']->value)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($order === null) {
            return response()->json(['error' => 'Channel order was not found.'], 404);
        }

        $invoice = $order->statutoryInvoice;
        $statutory = [
            'invoice_number' => $invoice?->invoice_number,
            'status' => $invoice?->status?->value,
            'document_retrieval' => 'hmac_document_get',
        ];

        return response()->json([
            'order_no' => $order->order_no,
            'channel' => $order->channel->value,
            'source_type' => $order->source_type,
            'source_id' => $order->source_id,
            'status' => $order->status->value,
            'invoice_eligible' => $order->invoice_eligible,
            'statutory' => $statutory,
        ]);
    }

    public function document(Request $request, string $sourceType, string $sourceId): Response
    {
        $auth = $this->authenticator->authenticate($request);
        if ($auth['ok'] !== true) {
            return response()->json([
                'status' => $auth['replay'] ? ChannelIngestOutcome::Replay->value : ChannelIngestOutcome::Unauthorized->value,
                'error' => $auth['error'],
            ], $auth['http_status']);
        }

        try {
            $found = $this->documents->find(
                $auth['channel'],
                $sourceType,
                $sourceId,
                trim((string) $request->header('X-Desk-Customer', '')),
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['scope'])) {
                return response()->json([
                    'status' => 'not_eligible',
                    'error' => $errors['scope'][0] ?? 'This order is outside the 2026-09-01 invoice scope.',
                ], 403);
            }

            return response()->json([
                'status' => 'unavailable',
                'error' => $errors['document'][0] ?? 'The statutory PDF is not available.',
            ], 409);
        }

        if ($found === null) {
            return response()->json([
                'status' => 'not_found',
                'error' => 'Statutory document was not found for this channel order.',
            ], 404);
        }

        return response($found['binary'], 200, [
            'Content-Type' => $found['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$found['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
