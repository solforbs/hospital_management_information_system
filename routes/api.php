<?php

use App\Http\Controllers\Api\LisController;
use App\Http\Middleware\AuthenticateLabGateway;
use Illuminate\Support\Facades\Route;

// Laboratory instrument (LIS) API used by the on-site device gateway.
Route::prefix('lis/v1')->middleware([AuthenticateLabGateway::class, 'throttle:600,1'])->name('lis.')->group(function () {
    Route::post('heartbeat', [LisController::class, 'heartbeat'])->name('heartbeat');
    Route::get('orders', [LisController::class, 'orders'])->name('orders');
    Route::get('orders/{sample}', [LisController::class, 'order'])->where('sample', '[A-Za-z0-9\-_.]+')->name('order');
    Route::post('results', [LisController::class, 'results'])->name('results');
});
