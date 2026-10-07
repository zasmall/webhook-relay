<?php

declare(strict_types=1);

use App\Http\Controllers\Api\StoreEventController;
use App\Http\Middleware\EnforcePayloadSizeLimit;
use Illuminate\Support\Facades\Route;

// Source-facing API, authenticated with Sanctum tokens issued to sources.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/events', StoreEventController::class)
        ->middleware(EnforcePayloadSizeLimit::class)
        ->name('api.events.store');
});
