<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Road Webhooks
|--------------------------------------------------------------------------
| Single server-to-server endpoint for Road webhook deliveries. Mounted only
| when `road.webhooks.enabled` is true. Deliberately outside the `web` group:
| no session, no CSRF — authenticity comes from the signed `X-Road-Signature`
| header, verified by the `road.webhook` middleware.
*/

Route::post(config('road.webhooks.path', 'road/webhooks'), WebhookController::class)
    ->middleware('road.webhook')
    ->name('road.webhooks');
