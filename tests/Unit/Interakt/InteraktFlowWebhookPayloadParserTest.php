<?php

namespace Tests\Unit\Interakt;

use App\Services\Interakt\InteraktFlowWebhookPayloadParser;
use Tests\TestCase;

class InteraktFlowWebhookPayloadParserTest extends TestCase
{
    private InteraktFlowWebhookPayloadParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = app(InteraktFlowWebhookPayloadParser::class);
    }

    public function test_parses_decoded_response_json(): void
    {
        $payload = [
            'type' => 'message_api_flow_response',
            'data' => [
                'message' => [
                    'message' => [
                        'nfm_reply' => [
                            'response_json' => [
                                'flow_token' => 'abc.def',
                                'preferred_date' => '2026-07-05',
                                'preferred_time_slot' => 'morning',
                                'phone_number' => '9876543210',
                                'additional_notes' => 'Notes',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTrue($this->parser->isFlowResponse($payload));
        $this->assertSame('abc.def', $this->parser->flowToken($this->parser->responseJson($payload)));
        $this->assertSame([
            'preferred_date' => '2026-07-05',
            'preferred_time_slot' => 'morning',
            'phone_number' => '9876543210',
            'additional_notes' => 'Notes',
        ], $this->parser->bookingData($this->parser->responseJson($payload)));
    }

    public function test_parses_string_response_json(): void
    {
        $payload = [
            'type' => 'message_api_flow_response',
            'data' => [
                'message' => [
                    'message' => [
                        'nfm_reply' => [
                            'response_json' => json_encode([
                                'flow_token' => 'token-value',
                                'preferred_date' => '2026-07-06',
                                'preferred_time_slot' => 'afternoon',
                                'phone_number' => '9123456789',
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ],
        ];

        $responseJson = $this->parser->responseJson($payload);

        $this->assertIsArray($responseJson);
        $this->assertSame('token-value', $this->parser->flowToken($responseJson));
    }

    public function test_rejects_unused_flow_token(): void
    {
        $this->assertNull($this->parser->flowToken(['flow_token' => 'unused']));
    }
}
