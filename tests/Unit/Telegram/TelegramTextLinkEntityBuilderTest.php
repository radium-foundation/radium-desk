<?php

namespace Tests\Unit\Telegram;

use App\Support\Telegram\TelegramOperationalLinkFormatter;
use App\Support\Telegram\TelegramTextLinkEntityBuilder;
use Tests\TestCase;

class TelegramTextLinkEntityBuilderTest extends TestCase
{
    private TelegramTextLinkEntityBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(TelegramTextLinkEntityBuilder::class);
    }

    public function test_ascii_identifier_entity_offset_and_length(): void
    {
        $text = "Case: SC40157";
        $entity = $this->builder->textLinkEntity(
            $text,
            'SC40157',
            'https://desk.radiumbox.com/incidents/123',
        );

        $this->assertNotNull($entity);
        $this->assertSame('text_link', $entity['type']);
        $this->assertSame(6, $entity['offset']);
        $this->assertSame(7, $entity['length']);
        $this->assertSame('https://desk.radiumbox.com/incidents/123', $entity['url']);
    }

    public function test_emoji_before_identifier_uses_utf16_offset(): void
    {
        $text = "🔄 Case: SC40157";
        $entity = $this->builder->textLinkEntity(
            $text,
            'SC40157',
            'https://desk.radiumbox.com/incidents/123',
        );

        $this->assertNotNull($entity);
        $this->assertSame(9, $entity['offset']);
        $this->assertSame(7, $entity['length']);
    }

    public function test_rupee_symbol_before_amount_does_not_shift_refund_entity(): void
    {
        $text = implode("\n", [
            '💰 Refund submitted',
            '',
            'Refund: REF-2026-000212',
            'Amount: ₹499.00',
        ]);

        $entity = $this->builder->textLinkEntity(
            $text,
            'REF-2026-000212',
            'https://desk.radiumbox.com/refunds/456',
        );

        $this->assertNotNull($entity);
        $this->assertSame($this->builder->utf16CodeUnitOffset($text, mb_strpos($text, 'REF-2026-000212', 0, 'UTF-8')), $entity['offset']);
        $this->assertSame(15, $entity['length']);
    }

    public function test_unicode_customer_name_does_not_break_case_entity(): void
    {
        $text = implode("\n", [
            '🔄 Support reassigned to you',
            '',
            'Customer: Mohammad Nesar',
            'Case: SC40157',
        ]);

        $entity = $this->builder->textLinkEntity(
            $text,
            'SC40157',
            'https://desk.radiumbox.com/incidents/123',
        );

        $this->assertNotNull($entity);
        $this->assertSame($this->builder->utf16CodeUnitOffset($text, mb_strpos($text, 'SC40157', 0, 'UTF-8')), $entity['offset']);
    }

    public function test_multiple_entities_in_one_message(): void
    {
        $text = implode("\n", [
            '💰 Refund submitted',
            '',
            'Refund: REF-2026-000212',
            'Amount: ₹499.00',
            'Order: RD3497836',
        ]);

        $entities = $this->builder->buildForText($text, [
            ['text' => 'REF-2026-000212', 'url' => 'https://desk.radiumbox.com/refunds/456'],
            ['text' => 'RD3497836', 'url' => 'https://desk.radiumbox.com/dashboard/orders/789/customer-360'],
        ]);

        $this->assertCount(2, $entities);
        $this->assertSame('REF-2026-000212', $this->linkedText($text, $entities[0]));
        $this->assertSame('RD3497836', $this->linkedText($text, $entities[1]));
    }

    public function test_build_for_text_skips_missing_substrings(): void
    {
        $entities = $this->builder->buildForText('Case: SC40157', [
            ['text' => 'SC99999', 'url' => 'https://desk.example.com/incidents/1'],
        ]);

        $this->assertSame([], $entities);
    }

    public function test_message_without_entities_when_no_links(): void
    {
        $message = $this->builder->messageWithTextLinks('Case: SC40157', []);

        $this->assertSame('Case: SC40157', $message->text);
        $this->assertNull($message->entities);
    }

    public function test_unsafe_url_is_rejected(): void
    {
        $this->assertNull($this->builder->textLinkEntity(
            'Case: SC40157',
            'SC40157',
            'javascript:alert(1)',
        ));
    }

    private function linkedText(string $text, array $entity): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $slice = substr($utf16, $entity['offset'] * 2, $entity['length'] * 2);

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE');
    }
}
