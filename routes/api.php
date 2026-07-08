<?php

use App\Http\Controllers\Webhooks\BonvoiceWebhookController;
use App\Http\Controllers\Webhooks\CashfreeWebhookController;
use App\Http\Controllers\Webhooks\InteraktFlowWebhookController;
use App\Http\Controllers\Webhooks\InteraktWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/cashfree', [CashfreeWebhookController::class, 'handle'])
    ->name('webhooks.cashfree');

Route::post('/webhooks/interakt', [InteraktWebhookController::class, 'handle'])
    ->name('webhooks.interakt');

Route::post('/webhooks/interakt/flow', [InteraktFlowWebhookController::class, 'handle'])
    ->name('webhooks.interakt.flow');

Route::post('/webhooks/bonvoice', [BonvoiceWebhookController::class, 'handle'])
    ->name('webhooks.bonvoice');
