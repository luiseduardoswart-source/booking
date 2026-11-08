<?php

use App\Http\Controllers\Api\BookingApiController;
use App\Http\Controllers\Api\PropertyApiController;
use App\Http\Controllers\Api\QuoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Publiek: accommodaties bekijken en prijzen/beschikbaarheid opvragen.
    Route::get('/properties', [PropertyApiController::class, 'index']);
    Route::get('/properties/{property}', [PropertyApiController::class, 'show']);
    Route::get('/properties/{property}/quote', QuoteController::class);
    Route::get('/properties/{property}/unavailable-dates', \App\Http\Controllers\Api\UnavailableDatesController::class);

    // Beveiligd (Sanctum-token): boekingen beheren.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/bookings', [BookingApiController::class, 'index']);
        Route::get('/bookings/{booking}', [BookingApiController::class, 'show']);
        Route::post('/bookings', [BookingApiController::class, 'store']);
    });
});
