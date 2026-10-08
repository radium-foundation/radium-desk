<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletSemanticInvariantVerifier
{
    public function __construct(
        private readonly CentralWalletContractCatalog $catalog,
        private readonly WalletVisibilityContractValidator $validator,
    ) {}

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function verifyProvider(): array
    {
        $checks = [
            $this->checkContractInvariantsDocumented(),
            $this->checkUnavailableNotZeroFixture(),
            $this->checkAuthoritativeZeroFixture(),
            $this->checkUnverifiedPositiveFixture(),
        ];

        return [
            'status' => $this->aggregate($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function aggregate(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'FAIL') {
                return 'FAIL';
            }
        }

        return 'PASS';
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContractInvariantsDocumented(): array
    {
        $contract = $this->catalog->loadContract();
        $invariants = $contract['invariants'] ?? [];
        $hasUnavailableInvariant = false;

        foreach ($invariants as $invariant) {
            if (is_string($invariant) && str_contains(strtoupper($invariant), 'UNAVAILABLE')) {
                $hasUnavailableInvariant = true;
            }
        }

        return [
            'id' => 'contract_invariants_documented',
            'result' => $hasUnavailableInvariant ? 'PASS' : 'FAIL',
            'details' => ['invariant_count' => is_countable($invariants) ? count($invariants) : 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUnavailableNotZeroFixture(): array
    {
        $contract = $this->catalog->loadContract();
        $semantics = $contract['spoke_failure_semantics'] ?? [];
        $mustNotMapToZero = ($semantics['must_not_map_to_zero_balance'] ?? false) === true;

        $errors = $this->catalog->loadFixture('fixtures/wallet-visibility-errors.json');
        $documentsUnavailable = isset($errors['route_missing']) || isset($errors['feature_disabled']);

        return [
            'id' => 'unavailable_not_zero_contract',
            'result' => $mustNotMapToZero && $documentsUnavailable ? 'PASS' : 'FAIL',
            'details' => [
                'must_not_map_to_zero_balance' => $mustNotMapToZero,
                'unavailable_fixtures_documented' => $documentsUnavailable,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAuthoritativeZeroFixture(): array
    {
        $responses = $this->catalog->loadFixture('fixtures/wallet-visibility-responses.json');
        $zero = $responses['authoritative_zero'] ?? null;
        $valid = is_array($zero);

        if ($valid) {
            try {
                $this->validator->validateSuccessBody($zero);
            } catch (\Throwable) {
                $valid = false;
            }
        }

        return [
            'id' => 'authoritative_zero_distinct',
            'result' => $valid && $this->validator->isAuthoritativeZero($zero) ? 'PASS' : 'FAIL',
            'details' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUnverifiedPositiveFixture(): array
    {
        $responses = $this->catalog->loadFixture('fixtures/wallet-visibility-responses.json');
        $positive = $responses['unverified_positive'] ?? null;

        if (! is_array($positive)) {
            return ['id' => 'unverified_positive_not_zero', 'result' => 'FAIL', 'details' => []];
        }

        $this->validator->validateSuccessBody($positive);
        $notZero = ! $this->validator->isAuthoritativeZero($positive);

        return [
            'id' => 'unverified_positive_not_zero',
            'result' => $notZero && bccomp((string) ($positive['wallet_balance'] ?? '0'), '0', 2) > 0 ? 'PASS' : 'FAIL',
            'details' => [],
        ];
    }
}
