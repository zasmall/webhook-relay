<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\EndpointController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('endpoints', EndpointController::class)->except(['edit']);
    Route::get('endpoints/{endpoint}/secret', [EndpointController::class, 'secret'])
        ->middleware(RequirePassword::class)
        ->name('endpoints.secret');
    Route::post('endpoints/{endpoint}/rotate-secret', [EndpointController::class, 'rotateSecret'])
        ->name('endpoints.rotate-secret');
    Route::post('endpoints/{endpoint}/replay', [EndpointController::class, 'replay'])
        ->name('endpoints.replay');

    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::post('deliveries/replay', [DeliveryController::class, 'replaySelected'])->name('deliveries.replay-selected');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
    Route::post('deliveries/{delivery}/replay', [DeliveryController::class, 'replay'])->name('deliveries.replay');
});

require __DIR__.'/settings.php';
