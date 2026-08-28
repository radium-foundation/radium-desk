<?php

use App\Http\Controllers\Api\Commerce\V1\CatalogController;
use App\Http\Controllers\Api\Commerce\V1\HealthController;
use App\Http\Controllers\Api\Commerce\V1\QuoteController;
use App\Http\Controllers\Api\Commerce\V1\SiteController;
use App\Http\Middleware\Commerce\AuthenticateCommerceSite;
use App\Http\Middleware\Commerce\EnsureAuthenticatedCommerceSiteMatchesRoute;
use App\Http\Middleware\Commerce\EnsureCommerceEnabled;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commerce API v1
|--------------------------------------------------------------------------
|
| Public health is available without site authentication.
| Protected routes require X-Site-Id and Authorization: Bearer <site_api_key>.
|
| Rate limiting: attach throttle middleware in a future Commerce phase.
|
*/

Route::prefix('v1/commerce')->name('commerce.v1.')->group(function (): void {
    Route::get('/health', [HealthController::class, 'show'])->name('health');

    Route::middleware([
        EnsureCommerceEnabled::class,
        AuthenticateCommerceSite::class,
    ])->group(function (): void {
        Route::get('/site', [SiteController::class, 'show'])->name('site');

        Route::middleware([
            EnsureAuthenticatedCommerceSiteMatchesRoute::class,
        ])->prefix('sites/{site}')->group(function (): void {
            Route::get('/catalog/brands', [CatalogController::class, 'brands'])->name('catalog.brands');
            Route::get('/catalog/brands/{brandId}/models', [CatalogController::class, 'models'])->name('catalog.models');
            Route::get('/catalog/models/{modelId}/plans', [CatalogController::class, 'plans'])->name('catalog.plans');
            Route::get('/catalog/models/{modelId}/quote', [QuoteController::class, 'show'])->name('catalog.quote');
        });
    });
});
