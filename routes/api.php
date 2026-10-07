<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\EndpointController;
use App\Http\Controllers\Api\ReplayEndpointDeliveriesController;
use App\Http\Controllers\Api\StoreEventController;
use App\Http\Middleware\EnforcePayloadSizeLimit;
use Illuminate\Support\Facades\Route;

// Sources publish events with their own tokens.
Route::middleware(['auth:sanctum', 'token.for:source'])->group(function () {
    Route::post('/events', StoreEventController::class)
        ->middleware(EnforcePayloadSizeLimit::class)
        ->name('api.events.store');
});

// Operators manage endpoints with user tokens (php artisan relay:user:token).
Route::middleware(['auth:sanctum', 'token.for:user'])->name('api.')->group(function () {
    Route::apiResource('endpoints', EndpointController::class);
    Route::post('endpoints/{endpoint}/rotate-secret', [EndpointController::class, 'rotateSecret'])
        ->name('endpoints.rotate-secret');

    Route::get('endpoints/{endpoint}/deliveries', [DeliveryController::class, 'index'])->name('endpoints.deliveries.index');
    Route::post('endpoints/{endpoint}/replay', ReplayEndpointDeliveriesController::class)->name('endpoints.replay');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
    Route::post('deliveries/{delivery}/replay', [DeliveryController::class, 'replay'])->name('deliveries.replay');
});
