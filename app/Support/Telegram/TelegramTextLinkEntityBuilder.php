<?php

namespace App\Support\Telegram;

use App\Data\Telegram\TelegramOutboundMessage;

class TelegramTextLinkEntityBuilder
{
    /**
     * @param  list<array{text: string, url: string}>  $links
     * @return list<array{type: string, offset: int, length: int, url: string}>
     */
    public function buildForText(string $text, array $links): array
    {
        $entities = [];

        foreach ($links as $link) {
            $linkText = (string) ($link['text'] ?? '');
            $url = trim((string) ($link['url'] ?? ''));

            if ($linkText === '' || $url === '') {
                continue;
            }

            $entity = $this->textLinkEntity($text, $linkText, $url);

            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        usort($entities, fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return $entities;
    }

    /**
     * @param  list<array{text: string, url: string}>  $links
     */
    public function messageWithTextLinks(string $text, array $links): TelegramOutboundMessage
    {
        $entities = $this->buildForText($text, $links);

        return new TelegramOutboundMessage(
            text: $text,
            entities: $entities === [] ? null : $entities,
        );
    }

    /**
     * @return array{type: string, offset: int, length: int, url: string}|null
     */
    public function textLinkEntity(string $fullText, string $linkText, string $url): ?array
    {
        if ($linkText === '' || ! preg_match('#^https?://#', trim($url))) {
            return null;
        }

        $characterOffset = mb_strpos($fullText, $linkText, 0, 'UTF-8');

        if ($characterOffset === false) {
            return null;
        }

        return [
            'type' => 'text_link',
            'offset' => $this->utf16CodeUnitOffset($fullText, $characterOffset),
            'length' => $this->utf16CodeUnitLength($linkText),
            'url' => trim($url),
        ];
    }

    public function utf16CodeUnitOffset(string $text, int $characterOffset): int
    {
        $prefix = mb_substr($text, 0, max(0, $characterOffset), 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function utf16CodeUnitLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
