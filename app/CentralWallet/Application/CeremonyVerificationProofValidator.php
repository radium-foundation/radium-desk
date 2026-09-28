<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\CeremonyVerificationProof;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CeremonyVerificationProofValidator
{
    public function __construct(
        private readonly int $clockSkewSeconds = 60,
    ) {}

    /**
     * @return array{proof: CeremonyVerificationProof, raw_payload: string}
     */
    public function validate(
        string $ceremonyVerificationRef,
        string $expectedSiteCode,
        string $expectedLocalUserId,
        ?string $expectedVerificationMethod = null,
    ): array {
        $secret = $this->signingSecretForSite($expectedSiteCode);
        if ($secret === null) {
            throw new InvalidArgumentException('ceremony_signing_secret_missing');
        }

        $parts = explode('.', $ceremonyVerificationRef, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('invalid_ceremony_proof_format');
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            throw new InvalidArgumentException('invalid_ceremony_proof_payload');
        }

        $expectedSignature = hash_hmac('sha256', $payloadJson, $secret, true);
        $providedSignature = $this->base64UrlDecode($encodedSignature);
        if ($providedSignature === null || ! hash_equals($expectedSignature, $providedSignature)) {
            throw new InvalidArgumentException('invalid_ceremony_proof_signature');
        }

        /** @var array<string, mixed>|null $claims */
        $claims = json_decode($payloadJson, true);
        if (! is_array($claims)) {
            throw new InvalidArgumentException('invalid_ceremony_proof_payload');
        }

        $audience = (string) config('central_wallet.ceremony.audience', 'radium-desk:ceremony-complete');
        if (($claims['aud'] ?? null) !== $audience) {
            throw new InvalidArgumentException('invalid_ceremony_proof_audience');
        }

        $siteCode = (string) ($claims['site_code'] ?? '');
        $localUserId = (string) ($claims['local_user_id'] ?? '');
        $ceremonyAttemptId = (string) ($claims['ceremony_attempt_id'] ?? '');
        $phoneHash = (string) ($claims['verified_phone_e164_hash'] ?? '');
        $verificationMethod = (string) ($claims['verification_method'] ?? '');
        $jti = (string) ($claims['jti'] ?? '');
        $issuedAt = (int) ($claims['iat'] ?? 0);
        $expiresAt = (int) ($claims['exp'] ?? 0);

        if ($siteCode === '' || $localUserId === '' || $ceremonyAttemptId === '' || $phoneHash === '' || $jti === '') {
            throw new InvalidArgumentException('invalid_ceremony_proof_claims');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $phoneHash)) {
            throw new InvalidArgumentException('invalid_ceremony_proof_phone_hash');
        }

        if ($siteCode !== $expectedSiteCode || $localUserId !== $expectedLocalUserId) {
            throw new InvalidArgumentException('invalid_ceremony_proof_binding');
        }

        if ($expectedVerificationMethod !== null
            && $expectedVerificationMethod !== ''
            && $verificationMethod !== $expectedVerificationMethod) {
            throw new InvalidArgumentException('invalid_ceremony_proof_verification_method');
        }

        $now = time();
        $proof = new CeremonyVerificationProof(
            jti: $jti,
            siteCode: $siteCode,
            localUserId: $localUserId,
            ceremonyAttemptId: $ceremonyAttemptId,
            verifiedPhoneE164Hash: $phoneHash,
            verificationMethod: $verificationMethod !== '' ? $verificationMethod : 'm2_whatsapp_otp',
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );

        if ($proof->isIssuedInFuture($now, $this->clockSkewSeconds)) {
            throw new InvalidArgumentException('invalid_ceremony_proof_issued_at');
        }

        if ($proof->isExpired($now)) {
            throw new InvalidArgumentException('invalid_ceremony_proof_expired');
        }

        return [
            'proof' => $proof,
            'raw_payload' => $payloadJson,
        ];
    }

    /**
     * Test helper: issue a signed proof for the given site secret configuration.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function issueForTesting(
        string $siteCode,
        string $localUserId,
        string $ceremonyAttemptId,
        string $verifiedPhoneE164Hash,
        string $secret,
        array $overrides = [],
    ): string {
        $now = time();
        $ttl = (int) config('central_wallet.ceremony.proof_ttl_seconds', 300);

        $claims = array_merge([
            'aud' => (string) config('central_wallet.ceremony.audience', 'radium-desk:ceremony-complete'),
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'ceremony_attempt_id' => $ceremonyAttemptId,
            'verified_phone_e164_hash' => $verifiedPhoneE164Hash,
            'verification_method' => 'm2_whatsapp_otp',
            'jti' => (string) ($overrides['jti'] ?? Str::uuid()),
            'iat' => $overrides['iat'] ?? $now,
            'exp' => $overrides['exp'] ?? ($now + $ttl),
        ], $overrides);

        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payloadJson, $secret, true);

        return self::base64UrlEncode($payloadJson).'.'.self::base64UrlEncode($signature);
    }

    private function signingSecretForSite(string $siteCode): ?string
    {
        /** @var array<string, string|null> $secrets */
        $secrets = config('central_wallet.ceremony.signing_secrets', []);
        $secret = $secrets[$siteCode] ?? null;

        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return trim($secret);
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
