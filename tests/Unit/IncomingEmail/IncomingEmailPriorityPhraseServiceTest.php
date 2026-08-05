<?php

namespace Tests\Unit\IncomingEmail;

use App\Models\IncomingEmailMessage;
use App\Services\IncomingEmail\IncomingEmailPriorityPhraseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailPriorityPhraseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_configured_phrase_case_insensitively(): void
    {
        config(['inbound_email.priority_phrases' => ['Legal Notice', 'RBI complaint']]);

        $message = IncomingEmailMessage::query()->make([
            'subject' => 'Re: LEGAL NOTICE from counsel',
            'from_email' => 'lawyer@example.com',
            'preview' => 'Please respond urgently.',
        ]);

        $match = app(IncomingEmailPriorityPhraseService::class)->match($message);

        $this->assertNotNull($match);
        $this->assertSame('Legal Notice', $match->matchedPhrase);
    }

    public function test_returns_null_when_no_phrases_configured(): void
    {
        config(['inbound_email.priority_phrases' => []]);

        $message = IncomingEmailMessage::query()->make([
            'subject' => 'Legal notice',
            'preview' => 'Urgent',
        ]);

        $this->assertNull(app(IncomingEmailPriorityPhraseService::class)->match($message));
    }
}
