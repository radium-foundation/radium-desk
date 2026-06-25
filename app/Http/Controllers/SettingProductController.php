<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSettingProductRequest;
use App\Http\Requests\UpdateSettingProductRequest;
use App\Models\SettingProduct;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;

class SettingProductController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $systemSettingsService,
    ) {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', SettingProduct::class);

            return $next($request);
        });
    }

    public function store(StoreSettingProductRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->systemSettingsService->createProduct(
            $validated['name'],
            (int) $validated['sort_order'],
        );

        return redirect()
            ->route('settings.index', ['tab' => 'products'])
            ->with('status', 'settings-product-created');
    }

    public function update(UpdateSettingProductRequest $request, SettingProduct $product): RedirectResponse
    {
        $validated = $request->validated();

        $this->systemSettingsService->updateProduct(
            $product,
            $validated['name'],
            (int) $validated['sort_order'],
        );

        return redirect()
            ->route('settings.index', ['tab' => 'products'])
            ->with('status', 'settings-product-updated');
    }

    public function toggle(SettingProduct $product): RedirectResponse
    {
        $this->systemSettingsService->toggleProduct($product, ! $product->is_enabled);

        return redirect()
            ->route('settings.index', ['tab' => 'products'])
            ->with('status', $product->fresh()->is_enabled ? 'settings-product-enabled' : 'settings-product-disabled');
    }
}
