# Road SDK for Laravel

Official Road SDK for Laravel apps — true BFF auth against the Road IAM
platform, with zero JWT exposure to the browser.

> **Status**: pre-alpha (BFF MVP). Authorization primitives and the full client
> surface (Members, Roles, Invitations, IAM) land in follow-up releases.

## Install

```bash
composer require b1-road/laravel
php artisan road:install
```

Set the five required env vars in `.env`:

```dotenv
ROAD_API_BASE_URL=https://api.road.b1.app
AUTH_SERVER_ISSUER_URL=https://auth.b1.app
AUTH_SERVER_AUDIENCE=...
AUTH_SERVER_CLIENT_ID=...
AUTH_SERVER_CLIENT_SECRET=...
```

Visit `/auth/road/login` to complete OIDC and start a session.

## Usage (MVP)

```php
use B1Road\Laravel\Facades\Road;

Route::middleware('road')->group(function () {
    Route::get('/whoami', fn () => Road::user()->toArray());
    Route::get('/me',     fn () => Road::client()->me()->get());
});
```

Frontend (Inertia + React):

```bash
php artisan vendor:publish --tag=road-inertia
```

```tsx
import { RoadInertiaProvider } from '@/lib/road-inertia-provider';

<RoadInertiaProvider>
  <App />
</RoadInertiaProvider>
```

`@b1-road/react` widgets work unmodified — they fetch through the
`/road-api/*` proxy with the Laravel session cookie.

## Quality bar

Bound by [`standards/SDK_DX_BAR.md`](../../../standards/SDK_DX_BAR.md). Full
plan: [`docs/plans/08-laravel-sdk-plan.md`](../../../docs/plans/08-laravel-sdk-plan.md).
