<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\AuthController;
use B1Road\Laravel\Http\Controllers\BusinessUnitController;
use B1Road\Laravel\Http\Controllers\WhoamiController;
use Illuminate\Support\Facades\Route;

// The OIDC browser flow needs the `web` group: StartSession (PKCE
// state + the BFF token store live in the session) and cookie
// handling. Without it `$request->session()` is unset and the login
// dance cannot persist state across the redirect → callback hop.
Route::middleware(['web', 'road.errors'])->prefix('auth/road')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('road.auth.login');
    Route::get('/callback', [AuthController::class, 'callback'])->name('road.auth.callback');
    Route::post('/logout', [AuthController::class, 'logout'])->name('road.auth.logout');
});

// These need the `web` group for the same reason the proxy does: the BFF
// token store lives in the session, so `road` (EnsureRoadAuthenticated) can
// only resolve the caller once StartSession has run. Without `web` every call
// 401s "unauthenticated" even for a logged-in browser (caught driving Beacon).
Route::middleware(['web', 'road.errors', 'road'])->group(function (): void {
    Route::get('/road/whoami', WhoamiController::class)->name('road.whoami');

    Route::post('/road/business-unit', [BusinessUnitController::class, 'set'])
        ->name('road.business-unit.set');
    Route::delete('/road/business-unit', [BusinessUnitController::class, 'clear'])
        ->name('road.business-unit.clear');
});
