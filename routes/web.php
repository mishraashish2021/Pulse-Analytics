<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Middleware\BasicDashboardAuth;
use Illuminate\Support\Facades\Route;

// ---- Analytics dashboard -------------------------------------------------
Route::middleware(BasicDashboardAuth::class)->prefix('dashboard')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
});

// ---- Restaurant demo app -------------------------------------------------
Route::get('/', [DemoController::class, 'home'])->name('demo.home');
Route::get('/menu', [DemoController::class, 'menu'])->name('demo.menu');
Route::get('/about', [DemoController::class, 'about'])->name('demo.about');
Route::post('/orders', [DemoController::class, 'store'])->name('demo.orders.store');
Route::get('/orders/{order}', [DemoController::class, 'order'])->name('demo.order');
