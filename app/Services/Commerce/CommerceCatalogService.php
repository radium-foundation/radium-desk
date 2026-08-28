<?php

namespace App\Services\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Models\Commerce\CommerceSite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CommerceCatalogService
{
    /**
     * @return Collection<int, CommerceCatalogBrand>
     */
    public function enabledBrandsForSite(CommerceSite $site): Collection
    {
        return CommerceCatalogBrand::query()
            ->where('commerce_site_id', $site->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }

    public function resolveBrandForSite(CommerceSite $site, string $brandSlug): CommerceCatalogBrand
    {
        $brand = CommerceCatalogBrand::query()
            ->where('commerce_site_id', $site->id)
            ->where('external_slug', $brandSlug)
            ->first();

        if ($brand === null || ! $brand->is_enabled) {
            throw ValidationException::withMessages([
                'brand' => ['The selected brand is not available.'],
            ]);
        }

        return $brand;
    }

    /**
     * @return Collection<int, CommerceCatalogModel>
     */
    public function enabledModelsForBrand(CommerceCatalogBrand $brand): Collection
    {
        return CommerceCatalogModel::query()
            ->where('brand_id', $brand->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }

    public function resolveModelForBrand(CommerceCatalogBrand $brand, int $modelId): CommerceCatalogModel
    {
        $model = CommerceCatalogModel::query()
            ->where('brand_id', $brand->id)
            ->whereKey($modelId)
            ->first();

        if ($model === null || ! $model->is_enabled) {
            throw ValidationException::withMessages([
                'model' => ['The selected model is not available.'],
            ]);
        }

        return $model;
    }

    public function resolveModelForSite(CommerceSite $site, int $modelId): CommerceCatalogModel
    {
        $model = CommerceCatalogModel::query()
            ->whereKey($modelId)
            ->whereHas('brand', fn ($query) => $query
                ->where('commerce_site_id', $site->id)
                ->where('is_enabled', true))
            ->where('is_enabled', true)
            ->first();

        if ($model === null) {
            throw ValidationException::withMessages([
                'model' => ['The selected model is not available for this site.'],
            ]);
        }

        return $model;
    }

    /**
     * @return Collection<int, CommerceCatalogPlan>
     */
    public function enabledPlansForModel(CommerceCatalogModel $model): Collection
    {
        return CommerceCatalogPlan::query()
            ->where('model_id', $model->id)
            ->where('is_enabled', true)
            ->orderBy('plan_type')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }

    public function resolvePlanForModel(
        CommerceCatalogModel $model,
        int $planId,
        ?string $expectedType = null,
    ): CommerceCatalogPlan {
        $plan = CommerceCatalogPlan::query()
            ->where('model_id', $model->id)
            ->whereKey($planId)
            ->first();

        if ($plan === null || ! $plan->is_enabled) {
            throw ValidationException::withMessages([
                'plan' => ['The selected plan is not available.'],
            ]);
        }

        if ($expectedType !== null && $plan->plan_type !== $expectedType) {
            throw ValidationException::withMessages([
                'plan' => ['The selected plan type is invalid.'],
            ]);
        }

        return $plan;
    }
}
