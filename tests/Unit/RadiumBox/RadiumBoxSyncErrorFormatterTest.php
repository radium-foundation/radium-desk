<?php

namespace Tests\Unit\RadiumBox;

use App\Support\RadiumBox\RadiumBoxSyncErrorFormatter;
use Tests\TestCase;

class RadiumBoxSyncErrorFormatterTest extends TestCase
{
    private RadiumBoxSyncErrorFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new RadiumBoxSyncErrorFormatter;
    }

    public function test_maps_order_not_found_to_friendly_message(): void
    {
        $message = $this->formatter->friendlyMessage(
            'RD Order not found',
            errorType: 'order_not_found',
        );

        $this->assertSame('Order not yet available in RadiumBox', $message);
    }

    public function test_maps_rate_limit_to_friendly_message(): void
    {
        $message = $this->formatter->friendlyMessage(
            'RadiumBox API rate limit exceeded (HTTP 429).',
            errorType: 'rate_limited',
        );

        $this->assertSame('RadiumBox rate limit reached. Please retry shortly.', $message);
    }

    public function test_maps_timeout_to_friendly_message(): void
    {
        $message = $this->formatter->friendlyMessage(
            'Connection timed out after 5000 milliseconds',
            errorType: 'connection_error',
        );

        $this->assertSame('RadiumBox did not respond.', $message);
    }

    public function test_maps_duplicate_serial_to_friendly_message(): void
    {
        $message = $this->formatter->friendlyMessage(
            'Duplicate serial prevented.',
            metadata: ['duplicate_serial' => true],
        );

        $this->assertSame('Serial already exists on another order.', $message);
    }

    public function test_maps_unknown_errors_to_generic_message(): void
    {
        $message = $this->formatter->friendlyMessage('Something unexpected broke.');

        $this->assertSame('Synchronization failed.', $message);
    }
}
