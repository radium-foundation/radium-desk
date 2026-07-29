<?php

namespace App\Services\IncomingEmail\Gmail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GmailApiClient
{
    /**
     * @var list<int>
     */
    private const RETRYABLE_STATUSES = [400, 429, 500, 502, 503, 504];

    private int $lastRequestRetries = 0;

    public function __construct(
        private readonly GmailAccessTokenService $accessTokenService,
    ) {}

    public function lastRequestRetries(): int
    {
        return $this->lastRequestRetries;
    }

    /**
     * @return array{historyId: string, emailAddress: ?string}
     */
    public function getProfile(string $mailbox): array
    {
        $response = $this->request($mailbox, 'GET', '/gmail/v1/users/me/profile');
        $historyId = (string) ($response['historyId'] ?? '');

        if ($historyId === '') {
            throw new RuntimeException('Gmail profile response missing historyId for '.$mailbox);
        }

        return [
            'historyId' => $historyId,
            'emailAddress' => isset($response['emailAddress']) ? (string) $response['emailAddress'] : null,
        ];
    }

    /**
     * @return array{
     *     historyId: string,
     *     entries: list<array{id: string, messageIds: list<string>}>,
     *     nextPageToken: ?string,
     *     expired: bool,
     *     retries: int,
     *     latency_ms: int
     * }
     */
    public function listHistoryPage(string $mailbox, string $startHistoryId, ?string $pageToken = null): array
    {
        $query = [
            'startHistoryId' => $startHistoryId,
            'maxResults' => max(1, (int) config('inbound_email.gmail.max_results_per_page', 100)),
        ];

        $historyTypes = config('inbound_email.gmail.history_types');

        if (is_array($historyTypes) && $historyTypes !== []) {
            $query['historyTypes'] = implode(',', $historyTypes);
        }

        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        $started = microtime(true);

        try {
            $result = $this->requestWithMeta($mailbox, 'GET', '/gmail/v1/users/me/history', $query, $startHistoryId);
        } catch (GmailApiException $exception) {
            if ($this->isHistoryExpired($exception)) {
                return [
                    'historyId' => $startHistoryId,
                    'entries' => [],
                    'nextPageToken' => null,
                    'expired' => true,
                    'retries' => max(0, $exception->attemptCount - 1),
                    'latency_ms' => $exception->elapsedMs,
                ];
            }

            throw $exception;
        } catch (RuntimeException $exception) {
            if ($this->isHistoryExpired($exception)) {
                return [
                    'historyId' => $startHistoryId,
                    'entries' => [],
                    'nextPageToken' => null,
                    'expired' => true,
                    'retries' => 0,
                    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                ];
            }

            throw $exception;
        }

        $response = $result['json'];
        $entries = [];

        foreach ($response['history'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $messageIds = $this->extractMessageIdsFromHistoryEntry($entry);

            if ($messageIds === []) {
                continue;
            }

            $entryId = isset($entry['id']) && is_scalar($entry['id']) ? (string) $entry['id'] : '';

            $entries[] = [
                'id' => $entryId,
                'messageIds' => $messageIds,
            ];
        }

        $latestHistoryId = $startHistoryId;

        if (isset($response['historyId']) && is_scalar($response['historyId'])) {
            $latestHistoryId = (string) $response['historyId'];
        }

        $nextPageToken = isset($response['nextPageToken']) && is_string($response['nextPageToken'])
            ? $response['nextPageToken']
            : null;

        return [
            'historyId' => $latestHistoryId,
            'entries' => $entries,
            'nextPageToken' => $nextPageToken,
            'expired' => false,
            'retries' => $result['retries'],
            'latency_ms' => $result['elapsed_ms'],
        ];
    }

    /**
     * @return array{historyId: string, messageIds: list<string>, expired: bool}
     */
    public function listHistoryMessageIds(string $mailbox, string $startHistoryId): array
    {
        $messageIds = [];
        $pageToken = null;
        $latestHistoryId = $startHistoryId;

        do {
            $page = $this->listHistoryPage($mailbox, $startHistoryId, $pageToken);

            if ($page['expired']) {
                return [
                    'historyId' => $startHistoryId,
                    'messageIds' => [],
                    'expired' => true,
                ];
            }

            foreach ($page['entries'] as $entry) {
                array_push($messageIds, ...$entry['messageIds']);
            }

            $latestHistoryId = $page['historyId'];
            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null);

        $orderedUnique = [];
        foreach ($messageIds as $messageId) {
            if (! isset($orderedUnique[$messageId])) {
                $orderedUnique[$messageId] = $messageId;
            }
        }

        return [
            'historyId' => $latestHistoryId,
            'messageIds' => array_values($orderedUnique),
            'expired' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(string $mailbox, string $messageId, ?string $historyId = null): array
    {
        $path = '/gmail/v1/users/me/messages/'.$messageId;

        try {
            $result = $this->requestWithMeta(
                $mailbox,
                'GET',
                $path,
                ['format' => 'full'],
                $historyId,
                $messageId,
            );

            return $result['json'];
        } catch (GmailStaleMessageException $exception) {
            throw $exception;
        } catch (GmailApiException $exception) {
            throw new GmailMessageFetchException(
                $mailbox,
                $messageId,
                $exception->httpStatus,
                $path,
                $exception->errorPayload,
                $exception->attemptCount,
                $exception->elapsedMs,
                $exception->requestId,
                $historyId ?? $exception->historyId,
                $exception,
            );
        }
    }

    public function getAttachmentBinary(string $mailbox, string $messageId, string $attachmentId): string
    {
        $response = $this->request(
            $mailbox,
            'GET',
            '/gmail/v1/users/me/messages/'.$messageId.'/attachments/'.$attachmentId,
        );

        $data = $response['data'] ?? null;

        if (! is_string($data) || $data === '') {
            throw new RuntimeException('Gmail attachment response missing data for '.$attachmentId);
        }

        $remainder = strlen($data) % 4;

        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Gmail attachment data could not be decoded for '.$attachmentId);
        }

        return $decoded;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function request(
        string $mailbox,
        string $method,
        string $path,
        array $query = [],
        ?string $historyId = null,
        ?string $messageId = null,
    ): array {
        return $this->requestWithMeta($mailbox, $method, $path, $query, $historyId, $messageId)['json'];
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array{json: array<string, mixed>, retries: int, elapsed_ms: int, request_id: ?string}
     */
    private function requestWithMeta(
        string $mailbox,
        string $method,
        string $path,
        array $query = [],
        ?string $historyId = null,
        ?string $messageId = null,
    ): array {
        $attempts = max(1, (int) config('inbound_email.gmail.http_retry_times', 3));
        $sleepMs = max(0, (int) config('inbound_email.gmail.http_retry_sleep_ms', 500));
        $token = $this->accessTokenService->tokenForMailbox($mailbox);
        $url = rtrim((string) config('inbound_email.gmail.api_base_url'), '/').$path;
        $started = microtime(true);
        $retries = 0;
        $lastException = null;
        $lastStatus = 0;
        $lastPayload = null;
        $lastRequestId = null;
        $this->lastRequestRetries = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $pending = Http::withToken($token)
                    ->acceptJson()
                    ->timeout((int) config('inbound_email.gmail.timeout_seconds', 20))
                    ->connectTimeout((int) config('inbound_email.gmail.connect_timeout_seconds', 5));

                /** @var Response $response */
                $response = match (strtoupper($method)) {
                    'GET' => $pending->get($url, $query),
                    default => throw new RuntimeException('Unsupported Gmail HTTP method: '.$method),
                };

                $elapsedMs = (int) round((microtime(true) - $started) * 1000);
                $requestId = $this->extractRequestId($response);
                $payload = $this->errorPayload($response);
                $lastStatus = $response->status();
                $lastPayload = $payload;
                $lastRequestId = $requestId;

                if ($response->status() === 404) {
                    if (str_contains($path, '/history')) {
                        throw new GmailApiException(
                            'Gmail historyId expired (404) for '.$mailbox,
                            $mailbox,
                            $path,
                            404,
                            $payload,
                            $messageId,
                            $historyId,
                            $attempt,
                            $elapsedMs,
                            $requestId,
                        );
                    }

                    if (preg_match('#/messages/([^/?]+)#', $path, $matches) === 1) {
                        throw new GmailStaleMessageException(
                            $mailbox,
                            $matches[1],
                            $path,
                            $requestId,
                            $elapsedMs,
                        );
                    }
                }

                if (in_array($response->status(), self::RETRYABLE_STATUSES, true) && $attempt < $attempts) {
                    $retries++;
                    $this->logRetry($mailbox, $path, $response->status(), $attempt, $payload, $messageId, $historyId, $requestId);
                    usleep($this->backoffMicroseconds($sleepMs, $attempt));

                    continue;
                }

                if (! $response->successful()) {
                    throw new GmailApiException(
                        sprintf(
                            'Gmail API %s %s failed for %s: HTTP %d',
                            $method,
                            $path,
                            $mailbox,
                            $response->status(),
                        ),
                        $mailbox,
                        $path,
                        $response->status(),
                        $payload,
                        $messageId,
                        $historyId,
                        $attempt,
                        $elapsedMs,
                        $requestId,
                    );
                }

                $json = $response->json();
                $this->lastRequestRetries = $retries;

                return [
                    'json' => is_array($json) ? $json : [],
                    'retries' => $retries,
                    'elapsed_ms' => $elapsedMs,
                    'request_id' => $requestId,
                ];
            } catch (GmailApiException $exception) {
                throw $exception;
            } catch (ConnectionException|RequestException $exception) {
                $lastException = $exception;
                $elapsedMs = (int) round((microtime(true) - $started) * 1000);

                if ($attempt < $attempts) {
                    $retries++;
                    $this->logRetry($mailbox, $path, 0, $attempt, ['message' => $exception->getMessage()], $messageId, $historyId, null);
                    usleep($this->backoffMicroseconds($sleepMs, $attempt));

                    continue;
                }

                throw new GmailApiException(
                    'Gmail API request failed after retries for '.$mailbox.': '.$exception->getMessage(),
                    $mailbox,
                    $path,
                    $lastStatus > 0 ? $lastStatus : 0,
                    is_array($lastPayload) ? $lastPayload : ['message' => $exception->getMessage()],
                    $messageId,
                    $historyId,
                    $attempt,
                    $elapsedMs,
                    $lastRequestId,
                    $exception,
                );
            } catch (Throwable $exception) {
                throw $exception;
            }
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        throw new GmailApiException(
            'Gmail API request failed after retries for '.$mailbox.': '.($lastException?->getMessage() ?? 'unknown'),
            $mailbox,
            $path,
            $lastStatus > 0 ? $lastStatus : 0,
            is_array($lastPayload) ? $lastPayload : null,
            $messageId,
            $historyId,
            $attempts,
            $elapsedMs,
            $lastRequestId,
            $lastException,
        );
    }

    private function backoffMicroseconds(int $sleepMs, int $attempt): int
    {
        $multiplier = 2 ** max(0, $attempt - 1);

        return (int) ($sleepMs * 1000 * $multiplier);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function logRetry(
        string $mailbox,
        string $endpoint,
        int $httpStatus,
        int $attempt,
        ?array $payload,
        ?string $messageId,
        ?string $historyId,
        ?string $requestId,
    ): void {
        Log::warning('[GmailInbound] Retrying Gmail API request.', [
            'mailbox' => $mailbox,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'google_error' => $payload,
            'message_id' => $messageId,
            'history_id' => $historyId,
            'attempt_count' => $attempt,
            'request_id' => $requestId,
        ]);
    }

    private function extractRequestId(Response $response): ?string
    {
        foreach (['X-Goog-Request-Id', 'x-goog-request-id', 'X-Request-Id', 'x-request-id'] as $header) {
            $value = $response->header($header);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function errorPayload(Response $response): ?array
    {
        $json = $response->json();

        if (! is_array($json)) {
            $body = trim($response->body());

            return $body === '' ? null : ['raw' => mb_substr($body, 0, 1000)];
        }

        if (isset($json['error']) && is_array($json['error'])) {
            return $json['error'];
        }

        return $json;
    }

    private function isHistoryExpired(RuntimeException $exception): bool
    {
        return $exception->getCode() === 404
            || str_contains(strtolower($exception->getMessage()), 'historyid expired');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function extractMessageIdsFromHistoryEntry(array $entry): array
    {
        $ids = [];

        foreach ($entry['messagesAdded'] ?? [] as $added) {
            $id = $added['message']['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        foreach ($entry['messages'] ?? [] as $message) {
            $id = $message['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
