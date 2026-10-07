<?php

use App\Http\Controllers\EndpointController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('endpoints', EndpointController::class)->except(['edit']);
    Route::get('endpoints/{endpoint}/secret', [EndpointController::class, 'secret'])
        ->middleware(RequirePassword::class)
        ->name('endpoints.secret');
    Route::post('endpoints/{endpoint}/rotate-secret', [EndpointController::class, 'rotateSecret'])
        ->name('endpoints.rotate-secret');
});

require __DIR__.'/settings.php';
