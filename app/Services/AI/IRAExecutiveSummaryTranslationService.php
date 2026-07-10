<?php

namespace App\Services\AI;

use App\Data\AI\IRAExecutiveSummaryDTO;

class IRAExecutiveSummaryTranslationService
{
    /**
     * @return array<string, mixed>
     */
    public function translateToHindi(IRAExecutiveSummaryDTO $summary): array
    {
        return [
            'executive_summary' => array_map(
                fn (string $line): string => $this->translateLine($line),
                $summary->executiveSummary,
            ),
            'opinion' => $this->translateLine($summary->opinion),
            'recommendation' => $this->translateLine($summary->recommendation),
        ];
    }

    /**
     * @param  array{executive_summary?: list<string>, opinion?: string, recommendation?: string}  $payload
     * @return array<string, mixed>
     */
    public function translatePayloadToHindi(array $payload): array
    {
        return [
            'executive_summary' => array_map(
                fn (string $line): string => $this->translateLine($line),
                $payload['executive_summary'] ?? [],
            ),
            'opinion' => $this->translateLine((string) ($payload['opinion'] ?? '')),
            'recommendation' => $this->translateLine((string) ($payload['recommendation'] ?? '')),
        ];
    }

    private function translateLine(string $line): string
    {
        $translated = trim($line);

        if ($translated === '') {
            return '';
        }

        foreach ($this->phraseMap() as $english => $hindi) {
            $translated = str_ireplace($english, $hindi, $translated);
        }

        return $translated;
    }

    /**
     * @return array<string, string>
     */
    private function phraseMap(): array
    {
        return [
            'Customer purchased' => 'ग्राहक ने खरीदा',
            'and currently has' => 'और वर्तमान में',
            'one active repair' => 'एक सक्रिय मरम्मत है',
            'active repairs' => 'सक्रिय मरम्मतें हैं',
            'The device serial number is still missing, causing service delay.' => 'डिवाइस का सीरियल नंबर अभी भी अनुपलब्ध है, जिससे सेवा में देरी हो रही है।',
            'Warranty cannot yet be verified.' => 'वारंटी अभी तक सत्यापित नहीं की जा सकती।',
            'Warranty appears expired; confirm chargeable repair expectations.' => 'वारंटी समाप्त प्रतीत होती है; शुल्क योग्य मरम्मत की अपेक्षाएँ स्पष्ट करें।',
            'Warranty appears active.' => 'वारंटी सक्रिय प्रतीत होती है।',
            'This case is already beyond SLA and should be prioritized.' => 'यह केस पहले से SLA से अधिक है और इसे प्राथमिकता देनी चाहिए।',
            'This case is approaching SLA limits and needs timely follow-up.' => 'यह केस SLA सीमा के निकट है और समय पर फॉलो-अप की आवश्यकता है।',
            'Review the current service case context before contacting the customer.' => 'ग्राहक से संपर्क करने से पहले वर्तमान सेवा केस का संदर्भ देखें।',
            'This appears to be a straightforward serial-number pending case. Obtaining the serial should unblock warranty validation and allow engineering to proceed.' => 'यह एक सीधा सीरियल-नंबर लंबित केस प्रतीत होता है। सीरियल प्राप्त करने से वारंटी सत्यापन अनब्लॉक होगा और इंजीनियरिंग आगे बढ़ सकेगी।',
            'This customer has experienced repeat failures and deserves proactive handling.' => 'इस ग्राहक को बार-बार विफलताएँ हुई हैं और उन्हें सक्रिय रूप से संभालने की आवश्यकता है।',
            'Customer expectations should be managed before repair begins.' => 'मरम्मत शुरू होने से पहले ग्राहक की अपेक्षाओं का प्रबंधन किया जाना चाहिए।',
            'This incident requires immediate attention to avoid further delay.' => 'आगे की देरी से बचने के लिए इस घटना पर तत्काल ध्यान देने की आवश्यकता है।',
            'Service progress depends on' => 'सेवा की प्रगति इस पर निर्भर है',
            'keep the customer informed while waiting.' => 'प्रतीक्षा के दौरान ग्राहक को सूचित रखें।',
            'This case can proceed with standard service handling once the next dependency is cleared.' => 'अगली बाधा दूर होने पर यह केस मानक सेवा प्रक्रिया से आगे बढ़ सकता है।',
            'Request the serial immediately' => 'तुरंत सीरियल माँगें',
            'verify warranty once received' => 'प्राप्त होने पर वारंटी सत्यापित करें',
            'proactively update the customer regarding SLA' => 'SLA के संबंध में ग्राहक को सक्रिय रूप से अपडेट करें',
            'confirm next steps with the customer' => 'ग्राहक के साथ अगले चरण की पुष्टि करें',
            'Confirm chargeable repair expectations with the customer before engineering begins work.' => 'इंजीनियरिंग कार्य शुरू करने से पहले ग्राहक के साथ शुल्क योग्य मरम्मत की अपेक्षाएँ स्पष्ट करें।',
            'Review prior technician notes, assign senior support if needed, and communicate a proactive repair plan.' => 'पिछले तकनीशियन नोट्स देखें, आवश्यकता हो तो वरिष्ठ सहायता सौंपें, और सक्रिय मरम्मत योजना साझा करें।',
            'Prioritize resolution, assign ownership, and send the customer an immediate status update.' => 'समाधान को प्राथमिकता दें, जिम्मेदारी सौंपें, और ग्राहक को तत्काल स्थिति अपडेट भेजें।',
            'Review incident details and contact the customer with the next update.' => 'घटना विवरण देखें और अगले अपडेट के साथ ग्राहक से संपर्क करें।',
            'Service is blocked only by missing device identification.' => 'सेवा केवल डिवाइस पहचान के अभाव में अवरुद्ध है।',
            'Serial number needs verification.' => 'सीरियल नंबर की पुष्टि आवश्यक है।',
            'Current serial confidence is' => 'वर्तमान सीरियल विश्वास',
            'The current serial number looks incorrect and should be confirmed with the customer before proceeding.' => 'वर्तमान सीरियल नंबर गलत लग रहा है और आगे बढ़ने से पहले ग्राहक से पुष्टि करनी चाहिए।',
            'The current serial number needs verification before warranty or repair work can proceed safely.' => 'वारंटी या मरम्मत से पहले वर्तमान सीरियल नंबर की पुष्टि आवश्यक है।',
            'This case is blocked until the device serial number is received from the customer.' => 'ग्राहक से सीरियल नंबर मिलने तक यह केस अवरुद्ध है।',
            'The customer has tried contacting multiple times and needs a prioritized callback.' => 'ग्राहक ने कई बार संपर्क किया है और प्राथमिकता वाले कॉलबैक की आवश्यकता है।',
            'Request the correct serial number from the customer before closing this case.' => 'केस बंद करने से पहले ग्राहक से सही सीरियल नंबर माँगें।',
            'Request the serial number from the customer immediately.' => 'ग्राहक से तुरंत सीरियल नंबर माँगें।',
            'Prioritize callback now and update the customer immediately.' => 'अभी कॉलबैक को प्राथमिकता दें और ग्राहक को तुरंत अपडेट करें।',
            'Review prior technician notes and communicate a proactive repair plan.' => 'पिछले तकनीशियन नोट्स देखें और सक्रिय मरम्मत योजना साझा करें।',
            'Prioritize resolution and send the customer an immediate status update.' => 'समाधान को प्राथमिकता दें और ग्राहक को तत्काल स्थिति अपडेट भेजें।',
        ];
    }
}
