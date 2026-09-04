<?php

use App\Http\Controllers\Api\V1\RadiumBoxReadController;
use Illuminate\Support\Facades\Route;

Route::get('orders', [RadiumBoxReadController::class, 'index'])
    ->name('orders.index');

Route::get('orders/{commercialId}', [RadiumBoxReadController::class, 'show'])
    ->whereNumber('commercialId')
    ->name('orders.show');
