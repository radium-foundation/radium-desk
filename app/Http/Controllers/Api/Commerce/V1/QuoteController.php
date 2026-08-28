<?php

namespace App\Http\Controllers\Api\Commerce\V1;

use App\Http\Controllers\Controller;
use App\Models\Commerce\CommerceSite;
use App\Services\Commerce\CommerceCatalogService;
use App\Services\Commerce\CommerceRdRenewalPricingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function __construct(
        private readonly CommerceCatalogService $catalogService,
        private readonly CommerceRdRenewalPricingEngine $pricingEngine,
    ) {}

    public function show(Request $request, string $site, int $modelId): JsonResponse
    {
        $commerceSite = $this->authenticatedSite($request);

        $validated = $request->validate([
            'rd_plan_id' => ['required', 'integer', 'min:1'],
            'amc_plan_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $model = $this->catalogService->resolveModelForSite($commerceSite, $modelId);
        $model->loadMissing('brand');

        $rdPlan = $this->catalogService->resolvePlanForModel(
            $model,
            (int) $validated['rd_plan_id'],
            'rd',
        );

        $amcPlan = null;
        if (! empty($validated['amc_plan_id'])) {
            $amcPlan = $this->catalogService->resolvePlanForModel(
                $model,
                (int) $validated['amc_plan_id'],
                'amc',
            );
        }

        $quote = $this->pricingEngine->quote(
            site: $commerceSite,
            brand: $model->brand,
            model: $model,
            rdPlan: $rdPlan,
            amcPlan: $amcPlan,
        );

        return response()->json($quote->toApiArray());
    }

    private function authenticatedSite(Request $request): CommerceSite
    {
        /** @var CommerceSite $site */
        $site = $request->attributes->get('commerce_site');

        return $site;
    }
}
