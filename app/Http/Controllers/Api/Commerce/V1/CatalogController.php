<?php

namespace App\Http\Controllers\Api\Commerce\V1;

use App\Http\Controllers\Controller;
use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Models\Commerce\CommerceSite;
use App\Services\Commerce\CommerceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CommerceCatalogService $catalogService,
    ) {}

    public function brands(Request $request, string $site): JsonResponse
    {
        $commerceSite = $this->authenticatedSite($request);

        $brands = $this->catalogService->enabledBrandsForSite($commerceSite);

        return $this->commerceResponse($commerceSite, [
            'brands' => $brands->map(fn (CommerceCatalogBrand $brand) => [
                'slug' => $brand->external_slug,
                'display_name' => $brand->display_name,
                'sort_order' => $brand->sort_order,
            ])->values()->all(),
        ]);
    }

    public function models(Request $request, string $site, string $brandId): JsonResponse
    {
        $commerceSite = $this->authenticatedSite($request);
        $brand = $this->catalogService->resolveBrandForSite($commerceSite, $brandId);
        $models = $this->catalogService->enabledModelsForBrand($brand);

        return $this->commerceResponse($commerceSite, [
            'brand' => [
                'slug' => $brand->external_slug,
                'display_name' => $brand->display_name,
            ],
            'models' => $models->map(fn (CommerceCatalogModel $model) => [
                'id' => $model->id,
                'display_name' => $model->display_name,
                'sort_order' => $model->sort_order,
            ])->values()->all(),
        ]);
    }

    public function plans(Request $request, string $site, int $modelId): JsonResponse
    {
        $commerceSite = $this->authenticatedSite($request);
        $model = $this->catalogService->resolveModelForSite($commerceSite, $modelId);
        $model->loadMissing('brand');
        $plans = $this->catalogService->enabledPlansForModel($model);

        return $this->commerceResponse($commerceSite, [
            'model' => [
                'id' => $model->id,
                'display_name' => $model->display_name,
                'brand_slug' => $model->brand->external_slug,
                'brand_name' => $model->brand->display_name,
            ],
            'plans' => $plans->map(fn (CommerceCatalogPlan $plan) => [
                'id' => $plan->id,
                'plan_type' => $plan->plan_type,
                'display_name' => $plan->display_name,
                'short_name' => $plan->short_name,
                'sort_order' => $plan->sort_order,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commerceResponse(CommerceSite $site, array $payload): JsonResponse
    {
        return response()->json([
            'site_id' => $site->site_id,
            'api_version' => '1',
            ...$payload,
        ]);
    }

    private function authenticatedSite(Request $request): CommerceSite
    {
        /** @var CommerceSite $site */
        $site = $request->attributes->get('commerce_site');

        return $site;
    }
}
