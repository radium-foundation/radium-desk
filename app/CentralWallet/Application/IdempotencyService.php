<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletIdempotencyRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class IdempotencyService
{
    private const PROCESSING_STATUS = 0;

    public function retentionDays(): int
    {
        return (int) config('central_wallet.idempotency_retention_days', 90);
    }

    /**
     * @param  callable(): array{status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $handler
     * @return array{replay: bool, status: int, body: array<string, mixed>}
     */
    public function execute(
        string $callerId,
        string $idempotencyKey,
        string $requestHash,
        callable $handler,
    ): array {
        return DB::transaction(function () use ($callerId, $idempotencyKey, $requestHash, $handler): array {
            $existing = CentralWalletIdempotencyRecord::query()
                ->where('caller_id', $callerId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->resolveExisting($existing, $requestHash);
            }

            try {
                $record = CentralWalletIdempotencyRecord::query()->create([
                    'caller_id' => $callerId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => self::PROCESSING_STATUS,
                    'response_body' => null,
                    'response_body_hash' => null,
                    'resource_type' => null,
                    'resource_id' => null,
                    'expires_at' => now()->addDays($this->retentionDays()),
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $existing = CentralWalletIdempotencyRecord::query()
                    ->where('caller_id', $callerId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->resolveExisting($existing, $requestHash);
            }

            $result = $handler();
            $sanitizedBody = IdempotencyResponseSanitizer::sanitize($result['body']);

            $record->update([
                'response_status' => $result['status'],
                'response_body' => $sanitizedBody,
                'response_body_hash' => $this->hashBody($sanitizedBody),
                'resource_type' => $result['resource_type'] ?? null,
                'resource_id' => $result['resource_id'] ?? null,
            ]);

            return [
                'replay' => false,
                'status' => $result['status'],
                'body' => $sanitizedBody,
            ];
        });
    }

    public function purgeExpired(): int
    {
        return CentralWalletIdempotencyRecord::query()
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * @return array{replay: bool, status: int, body: array<string, mixed>}
     */
    private function resolveExisting(CentralWalletIdempotencyRecord $existing, string $requestHash): array
    {
        if ($existing->request_hash !== $requestHash) {
            return [
                'replay' => true,
                'status' => 409,
                'body' => [
                    'error' => 'idempotency_key_reused_with_different_request',
                    'message' => 'Idempotency key was already used with a different request payload.',
                ],
            ];
        }

        if ($this->isProcessing($existing)) {
            return [
                'replay' => true,
                'status' => 409,
                'body' => [
                    'error' => 'idempotency_request_in_progress',
                    'message' => 'An identical idempotent request is still being processed.',
                ],
            ];
        }

        /** @var array<string, mixed> $storedBody */
        $storedBody = $existing->response_body ?? [];

        return [
            'replay' => true,
            'status' => (int) $existing->response_status,
            'body' => array_merge($storedBody, ['idempotent_replay' => true]),
        ];
    }

    private function isProcessing(CentralWalletIdempotencyRecord $record): bool
    {
        return (int) $record->response_status === self::PROCESSING_STATUS
            || $record->response_body === null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hashBody(array $body): string
    {
        return hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return $code === '23000' || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
