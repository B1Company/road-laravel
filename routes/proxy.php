<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\ProxyController;
use Illuminate\Support\Facades\Route;

$prefix = (string) config('road.proxy.prefix', 'road-api');

Route::middleware(['road.errors', 'road'])
    ->prefix($prefix)
    ->any('/{path?}', ProxyController::class)
    ->where('path', '.*')
    ->name('road.proxy');
