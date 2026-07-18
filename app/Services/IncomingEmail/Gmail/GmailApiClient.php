<?php

namespace App\Services\IncomingEmail\Gmail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GmailApiClient
{
    public function __construct(
        private readonly GmailAccessTokenService $accessTokenService,
    ) {}

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
     * @return array{historyId: string, messageIds: list<string>, expired: bool}
     */
    public function listHistoryMessageIds(string $mailbox, string $startHistoryId): array
    {
        $messageIds = [];
        $pageToken = null;
        $latestHistoryId = $startHistoryId;

        do {
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

            try {
                $response = $this->request($mailbox, 'GET', '/gmail/v1/users/me/history', $query);
            } catch (RuntimeException $exception) {
                if ($this->isHistoryExpired($exception)) {
                    return [
                        'historyId' => $startHistoryId,
                        'messageIds' => [],
                        'expired' => true,
                    ];
                }

                throw $exception;
            }

            foreach ($response['history'] ?? [] as $entry) {
                array_push($messageIds, ...$this->extractMessageIdsFromHistoryEntry($entry));
            }

            if (isset($response['historyId']) && is_scalar($response['historyId'])) {
                $latestHistoryId = (string) $response['historyId'];
            }

            $pageToken = isset($response['nextPageToken']) && is_string($response['nextPageToken'])
                ? $response['nextPageToken']
                : null;
        } while ($pageToken !== null);

        // Preserve Gmail history encounter order (first occurrence wins).
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
    public function getMessage(string $mailbox, string $messageId): array
    {
        return $this->request($mailbox, 'GET', '/gmail/v1/users/me/messages/'.$messageId, [
            'format' => 'full',
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function request(string $mailbox, string $method, string $path, array $query = []): array
    {
        $attempts = max(1, (int) config('inbound_email.gmail.http_retry_times', 3));
        $sleepMs = max(0, (int) config('inbound_email.gmail.http_retry_sleep_ms', 500));
        $token = $this->accessTokenService->tokenForMailbox($mailbox);
        $url = rtrim((string) config('inbound_email.gmail.api_base_url'), '/').$path;

        $lastException = null;

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

                if ($response->status() === 404) {
                    if (str_contains($path, '/history')) {
                        throw new RuntimeException('Gmail historyId expired (404) for '.$mailbox, 404);
                    }

                    if (preg_match('#/messages/([^/?]+)#', $path, $matches) === 1) {
                        throw new GmailStaleMessageException($mailbox, $matches[1]);
                    }
                }

                if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < $attempts) {
                    usleep($sleepMs * 1000 * $attempt);

                    continue;
                }

                if (! $response->successful()) {
                    throw new RuntimeException(sprintf(
                        'Gmail API %s %s failed for %s: HTTP %d',
                        $method,
                        $path,
                        $mailbox,
                        $response->status(),
                    ), $response->status());
                }

                $json = $response->json();

                return is_array($json) ? $json : [];
            } catch (ConnectionException|RequestException $exception) {
                $lastException = $exception;

                if ($attempt < $attempts) {
                    usleep($sleepMs * 1000 * $attempt);

                    continue;
                }
            }
        }

        throw new RuntimeException(
            'Gmail API request failed after retries for '.$mailbox.': '.($lastException?->getMessage() ?? 'unknown'),
            previous: $lastException,
        );
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
