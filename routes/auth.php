<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\AuthController;
use B1Road\Laravel\Http\Controllers\WhoamiController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth/road')->group(function (): void {
    Route::get('/login',   [AuthController::class, 'login'])->name('road.auth.login');
    Route::get('/callback', [AuthController::class, 'callback'])->name('road.auth.callback');
    Route::post('/logout',  [AuthController::class, 'logout'])->name('road.auth.logout');
});

Route::middleware('road')
    ->get('/road/whoami', WhoamiController::class)
    ->name('road.whoami');
