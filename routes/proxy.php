<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\ProxyController;
use Illuminate\Support\Facades\Route;

$prefix = (string) config('road.proxy.prefix', 'road-api');

// The `web` group is required, not optional: this is a browser-facing
// cookie-mode endpoint whose `road` middleware resolves the caller from the
// session-backed token store. Without StartSession (part of `web`) the session
// is never loaded, so every proxied call 401s and @b1-road/react widgets spin
// in an onUnauthenticated loop. `web` also brings CSRF (the React client sends
// the XSRF-TOKEN header) and cookie encryption.
Route::middleware(['web', 'road.errors', 'road'])
    ->prefix($prefix)
    ->any('/{path?}', ProxyController::class)
    ->where('path', '.*')
    ->name('road.proxy');
