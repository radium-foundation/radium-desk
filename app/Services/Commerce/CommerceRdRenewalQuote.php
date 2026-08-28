<?php

namespace App\Services\Commerce;

final class CommerceRdRenewalQuote
{
    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $rdPlan
     * @param  array<string, mixed>|null  $amcPlan
     * @param  array<string, mixed>  $rdLine
     * @param  array<string, mixed>|null  $amcLine
     * @param  array<string, mixed>  $durationLine
     * @param  array<string, mixed>  $taxBreakdown
     */
    public function __construct(
        public readonly string $siteId,
        public readonly array $model,
        public readonly array $rdPlan,
        public readonly ?array $amcPlan,
        public readonly array $rdLine,
        public readonly ?array $amcLine,
        public readonly array $durationLine,
        public readonly float $subtotal,
        public readonly array $taxBreakdown,
        public readonly float $taxTotal,
        public readonly float $payableAmount,
        public readonly int $cashfreeAmount,
    ) {}

    /**
     * Immutable snapshot suitable for future cart persistence.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'site_id' => $this->siteId,
            'model' => $this->model,
            'rd_plan' => $this->rdPlan,
            'amc_plan' => $this->amcPlan,
            'lines' => [
                'rd' => $this->rdLine,
                'amc' => $this->amcLine,
                'duration' => $this->durationLine,
            ],
            'subtotal' => $this->roundMoney($this->subtotal),
            'tax' => $this->taxBreakdown,
            'tax_total' => $this->roundMoney($this->taxTotal),
            'payable_amount' => $this->roundMoney($this->payableAmount),
            'cashfree_amount' => $this->cashfreeAmount,
            'pricing_version' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'api_version' => '1',
            'model' => $this->model,
            'rd_plan' => $this->rdPlan,
            'amc_plan' => $this->amcPlan,
            'lines' => [
                'rd' => $this->rdLine,
                'amc' => $this->amcLine,
                'duration' => $this->durationLine,
            ],
            'subtotal' => $this->roundMoney($this->subtotal),
            'tax' => $this->taxBreakdown,
            'tax_total' => $this->roundMoney($this->taxTotal),
            'payable_amount' => $this->roundMoney($this->payableAmount),
            'cashfree_amount' => $this->cashfreeAmount,
        ];
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
