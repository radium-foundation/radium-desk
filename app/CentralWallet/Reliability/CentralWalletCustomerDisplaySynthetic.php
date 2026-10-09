<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletCustomerDisplaySynthetic
{
    public function __construct(
        private readonly CentralWalletReleaseGateConfiguration $configuration,
    ) {}

    /**
     * Non-mutating spoke display-path synthetic (BalanceReadService when present).
     *
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function probe(): array
    {
        if ($this->configuration->role() === 'provider') {
            return [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'customer_display_provider_lane',
                    'result' => 'WARN',
                    'details' => ['message' => 'Customer display synthetic applies to spoke consumers'],
                ]],
            ];
        }

        if (! class_exists(\App\CentralWallet\Services\CentralWalletBalanceReadService::class)) {
            return [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'customer_display_not_applicable',
                    'result' => 'WARN',
                    'details' => ['message' => 'BalanceReadService not present on this project role'],
                ]],
            ];
        }

        $localUserId = (int) config('central_wallet.release_gate.customer_display.local_user_id', 0);
        if ($localUserId <= 0) {
            $localUserId = (int) config('central_wallet.release_gate.synthetic_probe.local_user_id', 0);
        }

        if ($localUserId <= 0) {
            return [
                'status' => 'BLOCKED',
                'checks' => [[
                    'id' => 'customer_display_fixture',
                    'result' => 'BLOCKED',
                    'details' => ['message' => 'FIXTURE NOT CONFIGURED'],
                ]],
            ];
        }

        try {
            /** @var \App\CentralWallet\Services\CentralWalletBalanceReadService $service */
            $service = app(\App\CentralWallet\Services\CentralWalletBalanceReadService::class);
            $result = $service->readForUser($localUserId);
        } catch (\Throwable $exception) {
            return [
                'status' => 'FAIL',
                'checks' => [[
                    'id' => 'customer_display_read',
                    'result' => 'FAIL',
                    'details' => ['message' => $exception->getMessage()],
                ]],
            ];
        }

        if (! is_object($result) || ! property_exists($result, 'state') || ! method_exists($result, 'shouldDisplay')) {
            return [
                'status' => 'FAIL',
                'checks' => [[
                    'id' => 'customer_display_result_type',
                    'result' => 'FAIL',
                    'details' => ['message' => 'Unexpected balance read result type'],
                ]],
            ];
        }

        $displayEnabled = filter_var(
            config('central_wallet.release_gate.customer_display.require_display_when_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        $checks = [
            [
                'id' => 'customer_display_service_resolved',
                'result' => 'PASS',
                'details' => ['local_user_id' => $localUserId],
            ],
            [
                'id' => 'customer_display_state',
                'result' => 'PASS',
                'details' => [
                    'state' => $result->state,
                    'should_display' => $result->shouldDisplay(),
                    'verification_required' => $result->verificationRequired ?? null,
                ],
            ],
        ];

        if ($displayEnabled && $result->state === 'disabled') {
            $checks[] = [
                'id' => 'customer_display_not_disabled',
                'result' => 'FAIL',
                'details' => ['message' => 'Balance read returned disabled while display required'],
            ];
        } else {
            $checks[] = [
                'id' => 'customer_display_not_disabled',
                'result' => 'PASS',
                'details' => [],
            ];
        }

        $status = 'PASS';
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'FAIL') {
                $status = 'FAIL';
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }
}
