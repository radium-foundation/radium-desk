<?php

namespace Tests\Unit\IncomingEmail\Gmail;

use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.gmail.api_base_url' => 'https://gmail.googleapis.com',
            'inbound_email.gmail.history_types' => null,
            'inbound_email.gmail.max_results_per_page' => 100,
            'inbound_email.gmail.http_retry_times' => 1,
        ]);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    public function test_list_history_omits_history_types_by_default(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [],
                'historyId' => '2000',
            ], 200),
        ]);

        app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '1000');

        Http::assertSent(function ($request): bool {
            return ! str_contains($request->url(), 'historyTypes=');
        });
    }

    public function test_list_history_sends_configured_history_types(): void
    {
        config(['inbound_email.gmail.history_types' => ['messageAdded', 'labelAdded']]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [],
                'historyId' => '2000',
            ], 200),
        ]);

        app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '1000');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'historyTypes=messageAdded%2ClabelAdded');
        });
    }

    public function test_list_history_collects_messages_added_only(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-a', 'threadId' => 'thr-a']],
                            ['message' => ['id' => 'msg-b', 'threadId' => 'thr-b']],
                        ],
                    ],
                ],
                'historyId' => '2100',
            ], 200),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '2000');

        $this->assertFalse($result['expired']);
        $this->assertSame('2100', $result['historyId']);
        $this->assertSame(['msg-a', 'msg-b'], $result['messageIds']);
    }

    public function test_list_history_collects_messages_only(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messages' => [
                            ['id' => 'msg-alias-1', 'threadId' => 'thr-1'],
                            ['id' => 'msg-alias-2', 'threadId' => 'thr-2'],
                        ],
                    ],
                ],
                'historyId' => '2200',
            ], 200),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '2000');

        $this->assertSame(['msg-alias-1', 'msg-alias-2'], $result['messageIds']);
    }

    public function test_list_history_collects_mixed_payload_in_encounter_order(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-added']],
                        ],
                        'messages' => [
                            ['id' => 'msg-listed'],
                        ],
                    ],
                    [
                        'messages' => [
                            ['id' => 'msg-second-entry'],
                        ],
                    ],
                ],
                'historyId' => '2300',
            ], 200),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '2000');

        $this->assertSame(
            ['msg-added', 'msg-listed', 'msg-second-entry'],
            $result['messageIds'],
        );
    }

    public function test_list_history_deduplicates_ids_preserving_first_occurrence(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-dup']],
                            ['message' => ['id' => 'msg-unique']],
                        ],
                        'messages' => [
                            ['id' => 'msg-dup'],
                            ['id' => 'msg-later'],
                        ],
                    ],
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-unique']],
                        ],
                    ],
                ],
                'historyId' => '2400',
            ], 200),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '2000');

        $this->assertSame(['msg-dup', 'msg-unique', 'msg-later'], $result['messageIds']);
    }

    public function test_list_history_paginates_and_tracks_latest_history_id(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::sequence()
                ->push([
                    'history' => [
                        ['messagesAdded' => [['message' => ['id' => 'msg-page-1']]]],
                    ],
                    'historyId' => '2500',
                    'nextPageToken' => 'page-2',
                ], 200)
                ->push([
                    'history' => [
                        ['messages' => [['id' => 'msg-page-2']]],
                    ],
                    'historyId' => '2600',
                ], 200),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', '2000');

        $this->assertSame(['msg-page-1', 'msg-page-2'], $result['messageIds']);
        $this->assertSame('2600', $result['historyId']);
        Http::assertSentCount(2);
    }

    public function test_list_history_returns_expired_when_start_history_id_is_too_old(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'error' => ['message' => 'Start history id is too old'],
            ], 404),
        ]);

        $result = app(GmailApiClient::class)->listHistoryMessageIds('support@radiumbox.com', 'stale');

        $this->assertTrue($result['expired']);
        $this->assertSame('stale', $result['historyId']);
        $this->assertSame([], $result['messageIds']);
    }

    public function test_get_message_throws_stale_message_exception_on_404(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-missing*' => Http::response([
                'error' => ['message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        $this->expectException(\App\Services\IncomingEmail\Gmail\GmailStaleMessageException::class);
        $this->expectExceptionMessage('msg-missing');

        app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-missing');
    }

    public function test_get_message_still_throws_on_server_error(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-error*' => Http::response([
                'error' => ['message' => 'Internal error'],
            ], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-error');
    }

    public function test_get_message_still_throws_on_forbidden(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-denied*' => Http::response([
                'error' => ['message' => 'Insufficient Permission'],
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');

        app(GmailApiClient::class)->getMessage('support@radiumbox.com', 'msg-denied');
    }
}
