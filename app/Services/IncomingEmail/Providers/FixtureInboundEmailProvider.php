<?php

namespace App\Services\IncomingEmail\Providers;

use App\Contracts\IncomingEmail\InboundEmailProvider;
use App\Data\IncomingEmail\NormalizedInboundEmail;

/**
 * Test/dev provider that returns a fixed set of normalized messages.
 * Production sync uses GmailInboundEmailProvider → IncomingEmailIngestService.
 */
class FixtureInboundEmailProvider implements InboundEmailProvider
{
    /** @var list<NormalizedInboundEmail> */
    private array $messages = [];

    /**
     * @param  list<NormalizedInboundEmail>  $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    /**
     * @param  list<NormalizedInboundEmail>  $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function pull(): array
    {
        return $this->messages;
    }
}
