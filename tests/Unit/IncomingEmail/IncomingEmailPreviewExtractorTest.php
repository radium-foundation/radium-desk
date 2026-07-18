<?php

namespace Tests\Unit\IncomingEmail;

use App\Services\IncomingEmail\IncomingEmailPreviewExtractor;
use Tests\TestCase;

class IncomingEmailPreviewExtractorTest extends TestCase
{
    private IncomingEmailPreviewExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inbound_email.preview_max_chars' => 500]);
        $this->extractor = app(IncomingEmailPreviewExtractor::class);
    }

    public function test_extracts_first_paragraph_from_plain_text(): void
    {
        $preview = $this->extractor->extract("First paragraph line one.\n\nSecond paragraph should be ignored.");

        $this->assertSame('First paragraph line one.', $preview);
    }

    public function test_limits_preview_to_configured_max_characters(): void
    {
        config(['inbound_email.preview_max_chars' => 20]);

        $preview = $this->extractor->extract('This paragraph is definitely longer than twenty characters.');

        $this->assertSame('This paragraph is de…', $preview);
    }

    public function test_extracts_preview_from_html_email(): void
    {
        $preview = $this->extractor->extract(
            null,
            '<html><body><p>Hello from <strong>HTML</strong>.</p><p>Second block.</p></body></html>',
        );

        $this->assertSame('Hello from HTML.', $preview);
    }

    public function test_falls_back_to_snippet_when_bodies_are_empty(): void
    {
        $preview = $this->extractor->extract(null, null, 'Snippet preview from Gmail.');

        $this->assertSame('Snippet preview from Gmail.', $preview);
    }
}
