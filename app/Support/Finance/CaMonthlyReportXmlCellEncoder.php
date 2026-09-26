<?php

namespace App\Support\Finance;

/**
 * Encodes worksheet cell values as standards-compliant OOXML fragments.
 */
final class CaMonthlyReportXmlCellEncoder
{
    private const MAX_INLINE_LENGTH = 32767;

    public function sanitizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        } elseif (! is_string($value)) {
            $value = (string) $value;
        }

        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/[\x{FFFE}\x{FFFF}]/u', '', $value) ?? '';

        if (strlen($value) > self::MAX_INLINE_LENGTH) {
            $value = substr($value, 0, self::MAX_INLINE_LENGTH);
        }

        return $value;
    }

    public function numericCellValue(mixed $value): ?string
    {
        $text = $this->sanitizeText($value);
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    public function inlineStringXml(mixed $value): string
    {
        $text = $this->sanitizeText($value);
        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_DISALLOWED, 'UTF-8');
        $space = $text !== trim($text) ? ' xml:space="preserve"' : '';

        return '<is><t'.$space.'>'.$escaped.'</t></is>';
    }
}
