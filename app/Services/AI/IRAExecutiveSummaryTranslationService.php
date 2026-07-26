<?php

namespace App\Services\AI;

use App\Data\AI\IRAExecutiveSummaryDTO;

/**
 * Translates Customer 360 Executive Narrative into spoken Hindi.
 *
 * Scope: executive_summary lines only. Opinion / recommendation are echoed unchanged.
 * Approach: protect business entities → translate whole sentences via patterns →
 * light lexical fallback for residual ops phrasing → restore entities.
 */
class IRAExecutiveSummaryTranslationService
{
    private const ENTITY_TOKEN = '⟦E%d⟧';

    /**
     * @return array{executive_summary: list<string>, opinion: string, recommendation: string}
     */
    public function translateToHindi(IRAExecutiveSummaryDTO $summary): array
    {
        return [
            'executive_summary' => array_map(
                fn (string $line): string => $this->translateNarrative($line),
                $summary->executiveSummary,
            ),
            'opinion' => $summary->opinion,
            'recommendation' => $summary->recommendation,
        ];
    }

    /**
     * @param  array{executive_summary?: list<string>, opinion?: string, recommendation?: string}  $payload
     * @return array{executive_summary: list<string>, opinion: string, recommendation: string}
     */
    public function translatePayloadToHindi(array $payload): array
    {
        return [
            'executive_summary' => array_map(
                fn (string $line): string => $this->translateNarrative($line),
                $payload['executive_summary'] ?? [],
            ),
            // Narrative-only scope — leave briefing companions untouched.
            'opinion' => (string) ($payload['opinion'] ?? ''),
            'recommendation' => (string) ($payload['recommendation'] ?? ''),
        ];
    }

    public function translateNarrative(string $narrative): string
    {
        $narrative = trim($narrative);

        if ($narrative === '') {
            return '';
        }

        [$protected, $entities] = $this->protectEntities($narrative);

        $sentences = $this->splitSentences($protected);
        $translated = [];

        foreach ($sentences as $sentence) {
            $translated[] = $this->translateSentence($sentence);
        }

        $joined = $this->joinSentences($translated);

        return $this->restoreEntities($joined, $entities);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function protectEntities(string $text): array
    {
        $entities = [];

        $patterns = [
            // Order / incident / inquiry references.
            '/\b(?:RD|RDE|SC|INQ)[-]?[A-Z0-9]+\b/iu',
            // Product models (FM220, FM 220, MFS 110, etc.).
            '/\b(?:FM|MFS|BIO)\s?\d{2,4}[A-Z]?\b/iu',
            // Dates used in appointment copy.
            '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},\s+\d{4}\b/u',
            // Fixed channel / system tokens.
            '/\b(?:WhatsApp|Email|Phone Call|IRA|SLA)\b/u',
            // Serial-like tokens (alphanumeric 6+, keep as-is).
            '/\b(?=[A-Z0-9]*\d)[A-Z0-9]{6,}\b/u',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback(
                $pattern,
                function (array $matches) use (&$entities): string {
                    $index = count($entities);
                    $entities[] = $matches[0];

                    return sprintf(self::ENTITY_TOKEN, $index);
                },
                $text,
            ) ?? $text;
        }

        // Protect Title-Case person names after role cues (owner / engineer / ownership).
        // Do not allow '.' inside name tokens — it would swallow the next sentence boundary.
        $person = '[A-Z][A-Za-z\'\-]*(?:\s+[A-Z][A-Za-z\'\-]*){0,3}';

        $text = preg_replace_callback(
            '/\b((?:Current owner|Engineer)\s*:\s*)('.$person.')\b/u',
            function (array $matches) use (&$entities): string {
                $index = count($entities);
                $entities[] = $matches[2];

                return $matches[1].sprintf(self::ENTITY_TOKEN, $index);
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b(Ownership changed from\s+)('.$person.')(\s*(?:→|->|to)\s*)('.$person.')\b/u',
            function (array $matches) use (&$entities): string {
                $fromIndex = count($entities);
                $entities[] = $matches[2];
                $toIndex = count($entities);
                $entities[] = $matches[4];

                return $matches[1]
                    .sprintf(self::ENTITY_TOKEN, $fromIndex)
                    .$matches[3]
                    .sprintf(self::ENTITY_TOKEN, $toIndex);
            },
            $text,
        ) ?? $text;

        return [$text, $entities];
    }

    /**
     * @param  list<string>  $entities
     */
    private function restoreEntities(string $text, array $entities): string
    {
        foreach ($entities as $index => $value) {
            $text = str_replace(sprintf(self::ENTITY_TOKEN, $index), $value, $text);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<string>  $sentences
     */
    private function joinSentences(array $sentences): string
    {
        $normalized = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            if (! preg_match('/[.!?।]$/u', $sentence)) {
                $sentence .= '।';
            }
            $normalized[] = $sentence;
        }

        return implode(' ', $normalized);
    }

    private function translateSentence(string $sentence): string
    {
        $trimmed = trim($sentence);
        $hadTerminal = (bool) preg_match('/[.!?]$/u', $trimmed);
        $core = rtrim($trimmed, " \t.!?");

        if ($core === '') {
            return $trimmed;
        }

        foreach ($this->sentencePatterns() as [$pattern, $builder]) {
            if (preg_match($pattern, $core, $matches) !== 1) {
                continue;
            }

            $hindi = $builder($matches);
            if ($hindi === null || $hindi === '') {
                continue;
            }

            return $hadTerminal ? rtrim($hindi, "।.!?").'।' : $hindi;
        }

        $lexically = $this->applyLexicalFallback($core);

        if ($lexically !== $core) {
            return $hadTerminal ? rtrim($lexically, "।.!?").'।' : $lexically;
        }

        // Unmatched sentence: return original (entity tokens restored later).
        return $trimmed;
    }

    /**
     * @return list<array{0: string, 1: callable(array<int|string, string>): (?string)}>
     */
    private function sentencePatterns(): array
    {
        return [
            [
                '/^This is a (critical-priority|high-priority) service case for (.+)$/iu',
                fn (array $m): string => 'यह '.$m[2].' का एक '.$this->priorityLabel($m[1]).' सर्विस केस है',
            ],
            [
                '/^This is an open service case for (.+)$/iu',
                fn (array $m): string => 'यह '.$m[1].' का एक ओपन सर्विस केस है',
            ],
            [
                '/^This is a high-priority service case\.?$/iu',
                fn (): string => 'यह एक हाई-प्रायोरिटी सर्विस केस है',
            ],
            [
                '/^The scheduled support appointment is overdue and (?:the visit has|has) not been completed$/iu',
                fn (): string => 'शेड्यूल्ड सपोर्ट अपॉइंटमेंट का समय निकल चुका है और विज़िट अभी पूरी नहीं हुई है',
            ],
            [
                '/^The scheduled support appointment is overdue$/iu',
                fn (): string => 'सपोर्ट अपॉइंटमेंट का समय निकल चुका है',
            ],
            [
                '/^The active support appointment is overdue$/iu',
                fn (): string => 'ऐक्टिव सपोर्ट अपॉइंटमेंट का समय निकल चुका है',
            ],
            [
                '/^Support appointment is overdue$/iu',
                fn (): string => 'सपोर्ट अपॉइंटमेंट का समय निकल चुका है',
            ],
            [
                '/^A support appointment is scheduled for (.+)$/iu',
                fn (array $m): string => $m[1].' को सपोर्ट अपॉइंटमेंट शेड्यूल है',
            ],
            [
                '/^A support appointment is scheduled and awaiting execution$/iu',
                fn (): string => 'सपोर्ट अपॉइंटमेंट शेड्यूल है और अभी पूरा होना बाकी है',
            ],
            [
                '/^A support appointment has already been completed$/iu',
                fn (): string => 'सपोर्ट अपॉइंटमेंट पहले ही पूरा हो चुका है',
            ],
            [
                '/^A support appointment is on the calendar$/iu',
                fn (): string => 'सपोर्ट अपॉइंटमेंट कैलेंडर पर है',
            ],
            [
                '/^Progress is blocked because the device serial number has not been provided$/iu',
                fn (): string => 'डिवाइस सीरियल नंबर न मिलने की वजह से काम आगे नहीं बढ़ पा रहा है',
            ],
            [
                '/^The case is waiting on serial-number verification before repair work can continue safely$/iu',
                fn (): string => 'रिपेयर आगे बढ़ाने से पहले सीरियल नंबर वेरिफाई होना बाकी है',
            ],
            [
                '/^The case has been delayed for (\d+) day\(s\) while waiting on the customer for (.+)$/iu',
                fn (array $m): string => 'ग्राहक से '.$m[2].' का इंतज़ार करते हुए केस '.$m[1].' दिन से अटका हुआ है',
            ],
            [
                '/^The case is currently waiting on the customer for (.+)$/iu',
                fn (array $m): string => 'अभी ग्राहक से '.$m[1].' का इंतज़ार है',
            ],
            [
                '/^The case is (.+)$/iu',
                fn (array $m): string => 'केस अभी '.$this->applyLexicalFallback($m[1]).' है',
            ],
            [
                '/^Current owner:\s*(.+)$/iu',
                fn (array $m): string => 'अभी का ओनर: '.$m[1],
            ],
            [
                '/^Engineer:\s*(.+)$/iu',
                fn (array $m): string => 'इंजीनियर: '.$m[1],
            ],
            [
                '/^Ownership changed from (.+?)\s*(?:→|->|to)\s*(.+?), increasing the risk of further delay$/iu',
                fn (array $m): string => 'ओनरशिप '.$m[1].' से '.$m[2].' को बदली है, जिससे और देरी का रिस्क बढ़ गया है',
            ],
            [
                '/^Device serial number is still missing$/iu',
                fn (): string => 'डिवाइस सीरियल नंबर अभी भी नहीं मिला है',
            ],
            [
                '/^Serial number still needs verification$/iu',
                fn (): string => 'सीरियल नंबर अभी भी वेरिफाई होना बाकी है',
            ],
            [
                '/^Customer has not replied$/iu',
                fn (): string => 'ग्राहक ने अभी तक जवाब नहीं दिया है',
            ],
            [
                '/^(.+) is on record$/iu',
                fn (array $m): string => $m[1].' रिकॉर्ड पर है',
            ],
            [
                '/^Payment is still outstanding for this case$/iu',
                fn (): string => 'इस केस का पेमेंट अभी बाकी है',
            ],
            [
                '/^SLA is already overdue, so further delay increases customer escalation risk$/iu',
                fn (): string => 'SLA पहले ही ओवरड्यू हो चुका है, और देरी से कस्टमर एस्केलेशन का रिस्क बढ़ेगा',
            ],
            [
                '/^SLA is approaching breach and needs prompt movement$/iu',
                fn (): string => 'SLA ब्रीच के करीब है, इसलिए जल्दी मूव करना ज़रूरी है',
            ],
            [
                '/^SLA is paused while waiting on the customer$/iu',
                fn (): string => 'ग्राहक के इंतज़ार में SLA पॉज़ है',
            ],
            [
                '/^Extended customer wait is slowing resolution$/iu',
                fn (): string => 'ग्राहक का लंबा इंतज़ार सॉल्यूशन को धीमा कर रहा है',
            ],
            [
                '/^Priority handling is required because this case is marked high impact$/iu',
                fn (): string => 'यह हाई-इम्पैक्ट केस है, इसलिए प्रायोरिटी हैंडलिंग ज़रूरी है',
            ],
            [
                '/^Missing or unverified serial data blocks warranty and repair decisions$/iu',
                fn (): string => 'सीरियल डेटा मिसिंग या अनवेरिफाइड होने से वारंटी और रिपेयर के फ़ैसले अटक रहे हैं',
            ],
            [
                '/^The case remains within normal operating risk if the next action is completed promptly$/iu',
                fn (): string => 'अगर अगला कदम जल्दी पूरा हो जाए तो केस नॉर्मल रिस्क में रहता है',
            ],
            [
                '/^The SLA has already been breached and immediate follow-up is required$/iu',
                fn (): string => 'SLA पहले ही ब्रीच हो चुका है और तुरंत फॉलो-अप ज़रूरी है',
            ],
            [
                '/^The serial number remains unverified although payment has been received$/iu',
                fn (): string => 'पेमेंट मिल चुका है, लेकिन सीरियल नंबर अभी भी वेरिफाई नहीं हुआ है',
            ],
            [
                '/^Review the current service case context before contacting the customer$/iu',
                fn (): string => 'ग्राहक से बात करने से पहले केस का मौजूदा कॉन्टेक्स्ट देख लें',
            ],
            // Legacy Phase-12 sentences (keep working without old phraseMap).
            [
                '/^Customer purchased (?:an |a )?(.+?) and currently has one active repair$/iu',
                fn (array $m): string => 'ग्राहक ने '.$m[1].' खरीदा है और अभी एक ऐक्टिव रिपेयर चल रहा है',
            ],
            [
                '/^The device serial number is still missing, causing service delay$/iu',
                fn (): string => 'डिवाइस सीरियल नंबर अभी भी नहीं मिला है, इसलिए सर्विस में देरी हो रही है',
            ],
            [
                '/^This case is already beyond SLA and should be prioritized$/iu',
                fn (): string => 'यह केस पहले से SLA से बाहर है, इसलिए इसे प्रायोरिटी दें',
            ],
            [
                '/^This case is approaching SLA limits and needs timely follow-up$/iu',
                fn (): string => 'यह केस SLA लिमिट के करीब है, इसलिए समय पर फॉलो-अप ज़रूरी है',
            ],
        ];
    }

    private function priorityLabel(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'critical-priority' => 'क्रिटिकल-प्रायोरिटी',
            'high-priority' => 'हाई-प्रायोरिटी',
            default => $priority,
        };
    }

    private function applyLexicalFallback(string $text): string
    {
        $replacements = [
            'device serial number' => 'डिवाइस सीरियल नंबर',
            'serial number' => 'सीरियल नंबर',
            'serial-number' => 'सीरियल-नंबर',
            'support appointment' => 'सपोर्ट अपॉइंटमेंट',
            'service case' => 'सर्विस केस',
            'high-priority' => 'हाई-प्रायोरिटी',
            'critical-priority' => 'क्रिटिकल-प्रायोरिटी',
            'customer' => 'ग्राहक',
            'has not replied' => 'ने अभी तक जवाब नहीं दिया है',
            'has not yet responded' => 'ने अभी तक जवाब नहीं दिया है',
            'no reply yet' => 'अभी जवाब नहीं मिला है',
            'still missing' => 'अभी भी नहीं मिला है',
            'still needs verification' => 'अभी भी वेरिफाई होना बाकी है',
            'needs verification' => 'वेरिफाई होना बाकी है',
            'is overdue' => 'का समय निकल चुका है',
            'further delay' => 'और देरी',
            'immediate follow-up' => 'तुरंत फॉलो-अप',
            'blocked because' => 'ब्लॉक है क्योंकि',
            'in progress' => 'प्रोग्रेस में',
        ];

        uksort($replacements, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $out = $text;
        foreach ($replacements as $english => $hindi) {
            $out = preg_replace('/'.preg_quote($english, '/').'/iu', $hindi, $out) ?? $out;
        }

        return $out;
    }
}
