<?php

use App\Http\Controllers\Webhooks\CashfreeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/cashfree', [CashfreeWebhookController::class, 'handle'])
    ->name('webhooks.cashfree');
