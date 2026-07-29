<?php

namespace Tests\Unit\IncomingEmail\Gmail;

use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use App\Services\IncomingEmail\Gmail\GmailMessageFetchException;
use App\Services\IncomingEmail\Gmail\GmailStaleMessageException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailApiClientResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.gmail.api_base_url' => 'https://gmail.googleapis.com',
            'inbound_email.gmail.history_types' => null,
            'inbound_email.gmail.max_results_per_page' => 100,
            'inbound_email.gmail.http_retry_times' => 3,
            'inbound_email.gmail.http_retry_sleep_ms' => 1,
        ]);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    public function test_get_message_retries_http_400_then_succeeds(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::sequence()
                ->push(['error' => ['message' => 'temporary']], 400)
                ->push(['id' => 'msg-1', 'threadId' => 'thr-1'], 200),
        ]);

        $message = app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-1');

        $this->assertSame('msg-1', $message['id']);
        Http::assertSentCount(2);
    }

    public function test_get_message_throws_fetch_exception_after_retry_exhaustion(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::response([
                'error' => ['message' => 'Invalid id value'],
            ], 400),
        ]);

        try {
            app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-1', '1000');
            $this->fail('Expected GmailMessageFetchException');
        } catch (GmailMessageFetchException $exception) {
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame('msg-1', $exception->messageId);
            $this->assertSame('Invalid id value', $exception->errorPayload['message'] ?? null);
            $this->assertSame(3, $exception->attemptCount);
        }
    }

    public function test_get_message_404_still_throws_stale_exception_without_retry_exhaustion_as_fetch(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-missing*' => Http::response([
                'error' => ['message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        $this->expectException(GmailStaleMessageException::class);

        app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-missing');
    }

    public function test_list_history_page_returns_entries_with_ids(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'id' => '2001',
                        'messagesAdded' => [['message' => ['id' => 'a']]],
                    ],
                ],
                'historyId' => '2100',
                'nextPageToken' => 'next',
            ], 200),
        ]);

        $page = app(GmailApiClient::class)->listHistoryPage('support@radiumbox.com', '2000');

        $this->assertFalse($page['expired']);
        $this->assertSame('2100', $page['historyId']);
        $this->assertSame('next', $page['nextPageToken']);
        $this->assertSame([
            ['id' => '2001', 'messageIds' => ['a']],
        ], $page['entries']);
    }
}
