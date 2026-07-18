<?php

namespace App\Services\IncomingEmail;

use Illuminate\Support\Str;

class IncomingEmailPreviewExtractor
{
    public function extract(
        ?string $plainText,
        ?string $html = null,
        ?string $fallbackSnippet = null,
        ?int $maxChars = null,
    ): ?string {
        $maxChars ??= max(1, (int) config('inbound_email.preview_max_chars', 500));

        $source = null;

        if ($plainText !== null && trim($plainText) !== '') {
            $source = $plainText;
        } elseif ($html !== null && trim($html) !== '') {
            $source = $this->htmlToPlainText($html);
        } elseif ($fallbackSnippet !== null && trim($fallbackSnippet) !== '') {
            $source = $fallbackSnippet;
        }

        if ($source === null) {
            return null;
        }

        $paragraph = $this->firstParagraph($source);

        if ($paragraph === '') {
            return null;
        }

        return Str::limit($paragraph, $maxChars, '…');
    }

    private function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace(
            '/<\s*\/\s*(p|div|li|tr|h[1-6])\s*>/i',
            "\n\n",
            $html,
        ) ?? $html;

        $withBreaks = preg_replace('/<\s*br\s*\/?>/i', "\n", $withBreaks) ?? $withBreaks;

        return html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function firstParagraph(string $text): string
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return '';
        }

        $blocks = preg_split("/\n\s*\n/", $normalized) ?: [];

        foreach ($blocks as $block) {
            $collapsed = trim(preg_replace('/[ \t]+/', ' ', $block) ?? '');

            if ($collapsed !== '') {
                return $collapsed;
            }
        }

        $firstLine = trim(explode("\n", $normalized)[0] ?? '');

        return $firstLine;
    }
}
