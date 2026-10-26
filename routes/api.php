<?php

use App\Http\Controllers\IngestController;
use App\Http\Middleware\AuthenticateIngest;
use Illuminate\Support\Facades\Route;

// POST /api/v1/events
// Browser SDK: key in the JSON body (so navigator.sendBeacon works).
// Server tracker: "Authorization: Bearer <secret key>".
Route::post('/v1/events', [IngestController::class, 'store'])
    ->middleware(AuthenticateIngest::class);
