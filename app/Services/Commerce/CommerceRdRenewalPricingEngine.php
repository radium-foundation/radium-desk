<?php

namespace App\Services\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Models\Commerce\CommerceSite;
use Illuminate\Validation\ValidationException;

class CommerceRdRenewalPricingEngine
{
    private const DURATION_GST_RATE = 18;

    public function quote(
        CommerceSite $site,
        CommerceCatalogBrand $brand,
        CommerceCatalogModel $model,
        CommerceCatalogPlan $rdPlan,
        ?CommerceCatalogPlan $amcPlan = null,
    ): CommerceRdRenewalQuote {
        $this->assertSiteEnabled($site);
        $this->assertBrandEnabledForSite($site, $brand);
        $this->assertModelBelongsToBrand($brand, $model);
        $this->assertModelEnabled($model);
        $this->assertRdPlan($model, $rdPlan);
        $this->assertAmcPlan($model, $amcPlan);

        $amount = (float) $rdPlan->selling_price;
        $paidLines = (float) $rdPlan->publish_price;

        if ($amcPlan !== null) {
            $amount += (float) $amcPlan->selling_price;
            $paidLines += (float) $amcPlan->publish_price;
        }

        $durationPrice = (float) $rdPlan->regular_price;
        $durationTax = $durationPrice * self::DURATION_GST_RATE / 100;
        $rdAmcTax = $paidLines - $amount;
        $taxTotal = $rdAmcTax + $durationTax;
        $payableAmount = $paidLines + $durationTax + $durationPrice;
        $subtotal = $amount + $durationPrice;

        $rdLine = $this->lineFromPlan($rdPlan, 'rd');
        $amcLine = $amcPlan !== null ? $this->lineFromPlan($amcPlan, 'amc') : null;
        $durationLine = [
            'label' => 'RD Technical Support — included',
            'selling_price' => $this->roundMoney($durationPrice),
            'tax' => $this->roundMoney($durationTax),
            'total' => $this->roundMoney($durationPrice + $durationTax),
        ];

        return new CommerceRdRenewalQuote(
            siteId: $site->site_id,
            model: $this->publicModel($model, $brand),
            rdPlan: $this->publicPlan($rdPlan),
            amcPlan: $amcPlan !== null ? $this->publicPlan($amcPlan) : null,
            rdLine: $rdLine,
            amcLine: $amcLine,
            durationLine: $durationLine,
            subtotal: $subtotal,
            taxBreakdown: [
                'rd_amc_gst' => $this->roundMoney($rdAmcTax),
                'duration_gst' => $this->roundMoney($durationTax),
                'duration_gst_rate_percent' => self::DURATION_GST_RATE,
            ],
            taxTotal: $taxTotal,
            payableAmount: $payableAmount,
            cashfreeAmount: (int) round($payableAmount),
        );
    }

    private function assertSiteEnabled(CommerceSite $site): void
    {
        if (! $site->is_enabled) {
            throw ValidationException::withMessages([
                'site' => ['The commerce site is disabled.'],
            ]);
        }
    }

    private function assertBrandEnabledForSite(CommerceSite $site, CommerceCatalogBrand $brand): void
    {
        if ($brand->commerce_site_id !== $site->id) {
            throw ValidationException::withMessages([
                'brand' => ['The selected brand is not available for this site.'],
            ]);
        }

        if (! $brand->is_enabled) {
            throw ValidationException::withMessages([
                'brand' => ['The selected brand is disabled.'],
            ]);
        }
    }

    private function assertModelBelongsToBrand(CommerceCatalogBrand $brand, CommerceCatalogModel $model): void
    {
        if ($model->brand_id !== $brand->id) {
            throw ValidationException::withMessages([
                'model' => ['The selected model does not belong to the selected brand.'],
            ]);
        }
    }

    private function assertModelEnabled(CommerceCatalogModel $model): void
    {
        if (! $model->is_enabled) {
            throw ValidationException::withMessages([
                'model' => ['The selected model is disabled.'],
            ]);
        }
    }

    private function assertRdPlan(CommerceCatalogModel $model, CommerceCatalogPlan $rdPlan): void
    {
        if ($rdPlan->model_id !== $model->id) {
            throw ValidationException::withMessages([
                'rd_plan' => ['The RD plan does not belong to the selected model.'],
            ]);
        }

        if (! $rdPlan->is_enabled) {
            throw ValidationException::withMessages([
                'rd_plan' => ['The RD plan is disabled.'],
            ]);
        }

        if (! $rdPlan->isRdPlan()) {
            throw ValidationException::withMessages([
                'rd_plan' => ['The selected plan is not an RD plan.'],
            ]);
        }
    }

    private function assertAmcPlan(CommerceCatalogModel $model, ?CommerceCatalogPlan $amcPlan): void
    {
        if ($amcPlan === null) {
            return;
        }

        if ($amcPlan->model_id !== $model->id) {
            throw ValidationException::withMessages([
                'amc_plan' => ['The AMC plan does not belong to the selected model.'],
            ]);
        }

        if (! $amcPlan->is_enabled) {
            throw ValidationException::withMessages([
                'amc_plan' => ['The AMC plan is disabled.'],
            ]);
        }

        if (! $amcPlan->isAmcPlan()) {
            throw ValidationException::withMessages([
                'amc_plan' => ['The selected plan is not an AMC plan.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFromPlan(CommerceCatalogPlan $plan, string $role): array
    {
        $selling = (float) $plan->selling_price;
        $publish = (float) $plan->publish_price;

        return [
            'role' => $role,
            'short_name' => $plan->short_name,
            'display_name' => $plan->display_name,
            'selling_price' => $this->roundMoney($selling),
            'publish_price' => $this->roundMoney($publish),
            'tax' => $this->roundMoney($publish - $selling),
            'total' => $this->roundMoney($publish),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicModel(CommerceCatalogModel $model, CommerceCatalogBrand $brand): array
    {
        return [
            'display_name' => $model->display_name,
            'brand_slug' => $brand->external_slug,
            'brand_name' => $brand->display_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPlan(CommerceCatalogPlan $plan): array
    {
        return [
            'plan_type' => $plan->plan_type,
            'short_name' => $plan->short_name,
            'display_name' => $plan->display_name,
        ];
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
