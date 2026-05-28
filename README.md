# Road SDK for Laravel

The official Road SDK for Laravel apps. **True BFF auth** against the Road
IAM platform — the Auth Server JWT is held by your Laravel process and
**never reaches the browser**.

> Status: pre-1.0 alpha (BFF MVP). Authorization primitives, the full
> Members/Roles/Invitations/IAM client surface, retry/idempotency, service
> mode, and webhooks are tracked in follow-up releases.

## Install

```bash
composer require b1-road/laravel
php artisan road:install
```

> Not yet published to Packagist — until the first release, require it from
> the monorepo path repository. The command above is the post-publish form.

Set five env vars in `.env` (the installer appends stubs for these):

```dotenv
ROAD_API_BASE_URL=https://api.road.b1.app
AUTH_SERVER_ISSUER_URL=https://auth.b1.app
AUTH_SERVER_CLIENT_ID=...
AUTH_SERVER_CLIENT_SECRET=...
AUTH_SERVER_REDIRECT_URI=https://your-app.com/auth/road/callback

# Optional — set only if your Auth Server issues project-scoped (audience'd)
# tokens. Left blank, the audience is neither requested nor validated.
AUTH_SERVER_AUDIENCE=
```

Verify the wiring:

```bash
php artisan road:doctor
```

Visit `/auth/road/login` to complete OIDC. After the callback, the
Laravel session is the source of truth for identity.

## Five-minute quickstart

### 1. Protect a route

```php
use B1Road\Laravel\Facades\Road;

Route::middleware('road')->group(function () {
    Route::get('/whoami', fn () => Road::user()->toArray());
    Route::get('/me',     fn () => Road::client()->me()->get());
});
```

`Road::user()` returns a `RoadUser` value object resolved from the
session-stored Auth Server tokens. `Road::client()` exposes the typed
Road API client.

### 2. Render Road widgets in Inertia

```bash
php artisan vendor:publish --tag=road-inertia
```

Wrap your app:

```tsx
import { RoadInertiaProvider } from '@/lib/road-inertia-provider';

export default function App({ children }) {
  return <RoadInertiaProvider>{children}</RoadInertiaProvider>;
}
```

`@b1-road/react` widgets (`<BusinessUnitSwitcher />`, `<BusinessUnitsMgmt />`)
work without any frontend JWT handling — they fetch through the
`/road-api/*` BFF proxy using the Laravel session cookie.

The `ShareRoadContext` middleware that hydrates `props.road` is
**auto-mounted into the `web` middleware group** when
`inertiajs/inertia-laravel` is installed — no manual middleware
registration. Opt out with `ROAD_INERTIA_ENABLED=false` if you need to
wire it manually (custom HTTP kernel, multiple Inertia setups, etc).
`road:doctor` verifies the wiring on every run.

## How auth works (BFF model)

```
Browser ── session cookie ──▶ Laravel ── Bearer (Auth Server JWT) ──▶ Road API
                              │
                              │ TokenStore (session)
                              ▼
                              Auth Server (OIDC discovery + JWKS)
```

- Browser holds only an `httpOnly; Secure; SameSite=Lax` Laravel session
  cookie.
- The Auth Server `access_token`, `refresh_token`, and `id_token` live in
  the BFF token store. Refresh rotation is invisible to the integrator
  and the browser.
- The `/road-api/{any?}` proxy forwards browser calls to Road. The
  browser sends the session cookie via `credentials: 'include'`; the
  proxy attaches the Bearer server-side.
- The Road SDK never gives the browser a JWT. This is *true BFF* as
  defined by the [IETF OAuth WG BCP for browser-based apps][bcp] —
  ranked above the Token-Mediating Backend pattern that earlier React
  SDK drafts used.

[bcp]: https://datatracker.ietf.org/doc/html/draft-ietf-oauth-browser-based-apps

## Server-side client

`Road::client()` mirrors `@b1-road/nestjs`'s client. The MVP surface:

```php
Road::client()->me()->get();                          // CurrentUser
Road::client()->me()->businessUnits();                // MyBusinessUnits
Road::client()->me()->permissions();                  // MyPermissions
Road::client()->businessUnits()->get($buId);          // BusinessUnitDetail
Road::client()->businessUnits($buId)->fetch();        // same; navigator style
```

Members / Roles / Invitations / IAM control plane methods will be added
in the next release — they are intentionally *not* stubbed so your IDE
autocomplete never offers a method that doesn't work.

## Authorization

Use Road's permission system on **your own custom routes**, not just
when proxying Road API calls. Three integrator entry points, all
backed by a single enforcement code path:

### Middleware string form (closures, inline routes)

```php
use B1Road\Laravel\Facades\Road;

Route::middleware(['road.errors', 'road', 'road.permission:read,Member,buId'])
    ->get('/bus/{buId}/members', fn (string $buId) => MyRepo::members($buId));
```

The args are `action, Subject, scopeSource`. `scopeSource` is a route
parameter name by default (`buId`); prefix with `input:` to pull from
the request body/query (`input:business_unit_id`).

### PHP attribute (controllers)

```php
use B1Road\Laravel\Attributes\RequirePermission;
use B1Road\Laravel\Authorization\{Action, Subject};

class MembersController
{
    #[RequirePermission(Action::Read, Subject::Member, in: 'buId')]
    public function index(string $buId): JsonResponse { /* ... */ }

    #[RequirePermission(Action::Manage, Subject::Member, in: 'buId')]
    public function destroy(string $buId, string $memberId): JsonResponse { /* ... */ }
}
```

Apply `road.permission.attribute` middleware in the route group to
enable enforcement; the attribute also works at class level (with
`#[SkipAuthorization]` overriding for individual methods).

### Programmatic (anywhere)

```php
// Boolean predicate
if (! Road::can(Action::Read, Subject::Member)->in($buId)->check()) {
    return abort(403);
}

// Throws on deny with a structured DecisionTrace
Road::assert(Road::can(Action::Update, Subject::Role)->in($buId));

// Single round-trip for multiple checks
[$canRead, $canUpdate, $canDelete] = Road::canMany([
    Road::can(Action::Read,   Subject::Member),
    Road::can(Action::Update, Subject::Member),
    Road::can(Action::Delete, Subject::Member),
])->in($buId)->resolve();

// Inspect the decision (the "why" — same shape every Road SDK surfaces)
$trace = Road::can(Action::Read, Subject::Member)->in($buId)->trace();
// $trace->verdict, $trace->grants, $trace->reason, ...
```

### The permission algebra

Permissions are `"$action:$Subject"` strings. The enum cases match the
wire form exactly: `Action::Read->value === 'read'`,
`Subject::Member->value === 'Member'`. The wildcard `'*'` grants
everything in scope; `manage:Subject` grants every CRUD verb on that
Subject. Use `->raw('custom:Permission')` on a `Can` builder for
platform-defined permissions outside Road's canonical set.

## Errors

Every error thrown by the SDK is a `RoadException` subclass:

| Class | HTTP | `error.code` | When |
|---|---|---|---|
| `RoadAuthnException` | 401 | `unauthenticated` (or specific OIDC code) | No session, expired session, OIDC validation failure |
| `RoadAuthzException` | 403 | `permission_denied` | Authenticated but no grant. Carries a `DecisionTrace` rendered into the message. |
| `RoadNotFoundException` | 404 | `not_found` | Road API said 404 |
| `RoadNetworkException` | 502 | `network_error` | Unreachable upstream |
| `RoadApiException` | varies | varies | Catch-all for non-mapped statuses |

The `road.errors` middleware (auto-applied to `auth/road/*`,
`/road/whoami`, and `/road-api/*`) renders these as:

- **JSON** for `Accept: application/json`, XHR, or `/road-api/*`:
  ```json
  { "error": { "code": "unauthenticated", "message": "...", "requestId": "...", "docs": "..." } }
  ```
- **Redirect to login** for `text/html` 401 (with `intended=` and
  `error=` query params).
- **Flash + redirect to /** for other browser-flow errors.

## Testing

The SDK ships an in-memory fake — no Auth Server, no JWKS, no HTTP
traffic:

```php
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;

uses(ActsAsRoadUser::class);

it('lists my business units', function () {
    $fake = Road::fake(
        RoadScenario::make()
            ->withUser('u_owner', email: 'eduardo@b1.app', name: 'Eduardo')
            ->withBusinessUnit('bu_1', name: 'B1')
            ->withMember('bu_1', 'u_owner', roles: ['Owner'])
    );
    $this->actingAsRoadUser('u_owner');

    Route::middleware('road')->get('/my-bus', function () {
        return Road::client()->me()->businessUnits();
    });

    $this->getJson('/my-bus')->assertOk();
    $fake->assertCalled('GET', '/me/business-units');
});
```

`Road::fake($scenario)` swaps the container's `RoadClient` binding for a
test instance routed through an in-memory backend. The returned
`RoadFakeAssertions` object is the only supported assertions surface —
`assertCalled`, `assertNothingCalled`, `assertCallCount`.

## Telemetry

The HTTP transport fires events on a `RoadTelemetry` binding. The default
implementation (`NoopTelemetry`) ignores them. To collect metrics, bind
your own:

```php
use B1Road\Laravel\Telemetry\RoadTelemetry;

$this->app->bind(RoadTelemetry::class, MyPulseTelemetry::class);
```

Event shape matches `@b1-road/nestjs` and `@b1-road/react` —
`{ method, path, status, durationMs, requestId, traceId, attempts }` — so
one sink covers every Road SDK.

## Artisan commands

| Command | Purpose |
|---|---|
| `road:install` | Publish config + Inertia JS provider, append `.env` stubs |
| `road:doctor` | Connectivity + config smoke check (env, reachability, JWKS, clock skew, redirect_uri shape, session driver, middleware, proxy mount) |
| `road:whoami` | Print the session-stored user's claims |

## Configuration

The full config shape is published to `config/road.php`:

| Key | Description |
|---|---|
| `road.environment` | `production` / `sandbox` / `local` |
| `road.api.base_url` | Road API base URL |
| `road.api.timeout` | HTTP timeout in seconds (default 10) |
| `road.api.jwks_ttl` | OIDC discovery + JWKS cache TTL in seconds (default 600) |
| `road.auth_server.*` | OIDC client credentials + scopes |
| `road.proxy.enabled` | Auto-mount `/road-api/{any?}` (default true) |
| `road.proxy.prefix` | Proxy URL prefix (default `road-api`) |
| `road.proxy.allow` | Glob allowlist of paths the proxy will forward |
| `road.token_store` | Where BFF caches Auth Server tokens (`session` only in MVP) |
| `road.inertia.enabled` | Inject `props.road` into Inertia shared props (default true) |
| `road.debug.header_enabled` | Surface `DecisionTrace` on errors when `X-Road-Debug: 1` |

## Naming note

This SDK refers to the identity provider as **"Auth Server"** in all
public-facing surfaces (config keys, error messages, public types). The
concrete OIDC provider behind it is an implementation detail of the Road
platform, not an integrator concern — your integration targets the generic
Auth Server contract, never a specific vendor.

## Troubleshooting

**`oidc_state_mismatch` after callback.** The session was lost between
the login redirect and the callback. Check `SESSION_DOMAIN` matches your
app's host, and that your session cookie is `SameSite=Lax` (default).
Cross-origin React shells need `SameSite=None` + a CORS-cleared proxy
origin — not supported in this MVP.

**`invalid_token` on every request.** Likely clock skew. Run
`php artisan road:doctor` — it compares your clock against the Auth
Server's `Date` header. Anything above 30 seconds breaks JWT
validation. Fix: NTP sync.

**Auth Server returns `redirect_uri_mismatch`.** The `redirect_uri` in
your `.env` doesn't exactly match a redirect URI registered for your OIDC
app in the Auth Server console. Register the exact callback URL — scheme,
host, port, and path must all match — and retry.

**401 on every `/road-api/*` call.** The session is missing or expired.
Try visiting `/auth/road/login` directly in the browser. If that
redirects through the OIDC dance and lands back at `/`, the session
should be populated — confirm with `php artisan road:whoami`.

## Quality bar

This SDK is bound by [`standards/SDK_DX_BAR.md`][bar] — the canonical
quality principles every Road SDK is held to. The plan that produced
this MVP lives at [`docs/plans/08-laravel-sdk-plan.md`][plan].

[bar]: ../../../standards/SDK_DX_BAR.md
[plan]: ../../../docs/plans/08-laravel-sdk-plan.md
