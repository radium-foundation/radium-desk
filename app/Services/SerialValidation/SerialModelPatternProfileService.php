<?php

namespace App\Services\SerialValidation;

use App\Data\SerialValidation\SerialPatternAssessment;
use App\Data\SerialValidationResult;
use App\Enums\SerialInsightConfidence;
use Illuminate\Support\Str;

class SerialModelPatternProfileService
{
    public function __construct(
        private readonly ProductModelAliasNormalizer $aliasNormalizer,
        private readonly CanonicalProductResolver $productResolver,
    ) {}

    public function assess(
        ?string $productLabel,
        string $serial,
        SerialValidationResult $validation,
    ): SerialPatternAssessment {
        $canonicalProduct = $this->resolveProfileProduct($validation->product)
            ?? $this->resolveProfileProduct($productLabel);
        $profile = $this->profileFor($canonicalProduct);
        $normalizedSerial = $this->normalizeSerial($serial);
        $normalizedCompact = $this->compactSerial($normalizedSerial);

        if ($profile === null) {
            return new SerialPatternAssessment(
                canonicalProduct: $canonicalProduct,
                matchesVerifiedValid: false,
                matchesVerifiedWrong: false,
                wrongPatternReason: null,
                wrongPatternConfidence: null,
                crossModelHint: null,
                hasOvsIConfusion: false,
                failureGuidance: 'Ask customer for a clear device serial photo before proceeding.',
                validFormatDescription: 'expected device serial format',
            );
        }

        [$wrongPatternReason, $wrongPatternConfidence] = $this->matchWrongPattern(
            $profile,
            $normalizedSerial,
            $normalizedCompact,
        );

        return new SerialPatternAssessment(
            canonicalProduct: $canonicalProduct,
            matchesVerifiedValid: $this->matchesSampleList($profile['verified_valid'] ?? [], $normalizedSerial, $normalizedCompact),
            matchesVerifiedWrong: $this->matchesSampleList($profile['verified_wrong'] ?? [], $normalizedSerial, $normalizedCompact),
            wrongPatternReason: $wrongPatternReason,
            wrongPatternConfidence: $wrongPatternConfidence,
            crossModelHint: $this->resolveCrossModelHint($profile, $normalizedSerial, $normalizedCompact),
            hasOvsIConfusion: $this->detectOvsIConfusion($canonicalProduct, $normalizedSerial, $validation),
            failureGuidance: (string) ($profile['failure_guidance'] ?? 'Ask customer for a clear device serial photo before proceeding.'),
            validFormatDescription: (string) ($profile['valid_format_description'] ?? 'expected device serial format'),
        );
    }

    public function looksLikeKnownWrongEntry(?string $productLabel, string $serial): bool
    {
        $assessment = $this->assess($productLabel, $serial, SerialValidationResult::invalid($serial, $productLabel ?? 'unknown', ''));

        return $assessment->matchesVerifiedWrong
            || $assessment->wrongPatternReason !== null
            || $assessment->crossModelHint !== null;
    }

    public function patternMismatchExplanation(
        string $productLabel,
        SerialValidationResult $validation,
        SerialPatternAssessment $assessment,
    ): string {
        $productKey = $this->displayProductKey($productLabel, $assessment->canonicalProduct);
        $guidance = $assessment->failureGuidance;

        if ($assessment->crossModelHint !== null) {
            return "Serial number appears typical of {$assessment->crossModelHint} rather than {$productKey}. {$guidance}";
        }

        if ($assessment->hasOvsIConfusion) {
            return "Serial may contain O vs I confusion for {$productKey}. {$guidance}";
        }

        if ($assessment->wrongPatternReason !== null) {
            $reason = Str::lower($assessment->wrongPatternReason);

            if (Str::contains($reason, ['model name', 'model or part', 'part number', 'product label'])) {
                return "Customer may have submitted a product code instead of a serial number. {$guidance}";
            }

            return "Serial format does not match expected pattern for {$productKey}. {$guidance}";
        }

        if ($assessment->matchesVerifiedWrong) {
            return "Serial format does not match expected pattern for {$productKey}. {$guidance}";
        }

        return "Serial format does not match expected pattern for {$productKey}. {$guidance}";
    }

    public function crossModelVerificationExplanation(
        string $productLabel,
        SerialPatternAssessment $assessment,
    ): string {
        $productKey = $this->displayProductKey($productLabel, $assessment->canonicalProduct);

        if ($assessment->crossModelHint !== null) {
            return "Serial number appears typical of {$assessment->crossModelHint} rather than {$productKey}. {$assessment->failureGuidance}";
        }

        if ($assessment->matchesVerifiedWrong) {
            return "Serial format matches {$productKey} loosely but has been flagged as incorrect in production learning. {$assessment->failureGuidance}";
        }

        return "Serial number looks unusual for {$productKey}; verify with RadiumBox before proceeding. {$assessment->failureGuidance}";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileFor(?string $canonicalProduct): ?array
    {
        if ($canonicalProduct === null) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = config('serial_pattern_profiles', []);

        return $profiles[$canonicalProduct] ?? null;
    }

    /**
     * @param  list<string>  $samples
     */
    private function matchesSampleList(array $samples, string $normalizedSerial, string $normalizedCompact): bool
    {
        foreach ($samples as $sample) {
            $normalizedSample = $this->normalizeSerial($sample);
            $compactSample = $this->compactSerial($normalizedSample);

            if ($normalizedSerial === $normalizedSample || $normalizedCompact === $compactSample) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0: ?string, 1: ?SerialInsightConfidence}
     */
    private function matchWrongPattern(array $profile, string $normalizedSerial, string $normalizedCompact): array
    {
        foreach ($profile['wrong_patterns'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $regex = $rule['regex'] ?? null;

            if (! is_string($regex) || $regex === '') {
                continue;
            }

            if (preg_match($regex, $normalizedSerial) === 1 || preg_match($regex, $normalizedCompact) === 1) {
                $confidence = match ($rule['confidence'] ?? null) {
                    'high' => SerialInsightConfidence::High,
                    'medium' => SerialInsightConfidence::Medium,
                    default => SerialInsightConfidence::Medium,
                };

                return [(string) ($rule['reason'] ?? 'known invalid pattern'), $confidence];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function resolveCrossModelHint(array $profile, string $normalizedSerial, string $normalizedCompact): ?string
    {
        foreach ($profile['likely_other_model_serials'] ?? [] as $otherModel => $serials) {
            if (! is_string($otherModel) || ! is_array($serials)) {
                continue;
            }

            if ($this->matchesSampleList($serials, $normalizedSerial, $normalizedCompact)) {
                return $otherModel;
            }
        }

        return null;
    }

    private function detectOvsIConfusion(
        ?string $canonicalProduct,
        string $normalizedSerial,
        SerialValidationResult $validation,
    ): bool {
        if ($canonicalProduct !== 'MSO E3') {
            return false;
        }

        if (strlen($normalizedSerial) === 11 && str_contains($normalizedSerial, 'O')) {
            return true;
        }

        $reason = Str::lower((string) $validation->reason);

        return Str::contains($reason, ['5th character', 'o vs i', 'must have i']);
    }

    private function normalizeSerial(string $serial): string
    {
        return strtoupper(trim($serial));
    }

    private function compactSerial(string $normalizedSerial): string
    {
        return preg_replace('/\s+/', '', $normalizedSerial) ?? $normalizedSerial;
    }

    private function displayProductKey(string $productLabel, ?string $canonicalProduct): string
    {
        $label = $canonicalProduct ?? $productLabel;

        return strtoupper(str_replace(' ', '', $label));
    }

    private function resolveProfileProduct(?string $productLabel): ?string
    {
        if (! filled($productLabel)) {
            return null;
        }

        return $this->aliasNormalizer->resolve($productLabel)
            ?? $this->productResolver->resolve($productLabel);
    }
}
