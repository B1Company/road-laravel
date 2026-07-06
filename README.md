# Road SDK for Laravel

The official Road SDK for Laravel apps. **True BFF auth** against the Road
IAM platform — the Auth Server JWT is held by your Laravel process and
**never reaches the browser**.

> Status: pre-1.0 (`0.x` alpha). While Road serves the `alpha` API contract the
> surface may shift between minor versions; it stabilises when the API graduates
> `alpha` → `v1`. The SDK is feature-complete: BFF auth, the full
> Members/Roles/Invitations/IAM client with auto-pagination, authorization
> primitives, retries/idempotency, service-to-service mode, and webhooks.

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

`Road::client()` mirrors `@b1-road/nestjs`'s client one-to-one.

```php
// Me
Road::client()->me()->get();                          // CurrentUser
Road::client()->me()->businessUnits();                // MyBusinessUnits
Road::client()->me()->permissions();                  // MyPermissions

// Business units — get(), create(), update(), and the navigator shorthand
Road::client()->businessUnits()->get($buId);          // BusinessUnitDetail
Road::client()->businessUnits($buId)->fetch();        // same; navigator style
Road::client()->businessUnits()->create(['name' => 'B1']); // slug derived
Road::client()->businessUnits()->get($buId, include: ['members', 'roles']);

// Members / Roles / Invitations hang off the BU and act on themselves
foreach (Road::client()->businessUnits($buId)->members() as $member) { /* … */ }
Road::client()->businessUnits($buId)->members()->suspend($memberId);
Road::client()->businessUnits($buId)->members()->assignRole($memberId, $roleId); // BU or platform role
Road::client()->businessUnits($buId)->members()->revokeRole($memberId, $roleId);
Road::client()->businessUnits($buId)->roles()->create(['name' => 'Editor', 'permissions' => ['read:Member']]);
Road::client()->businessUnits($buId)->invitations()->create([
    'email' => 'x@b1.app',
    'roleId' => $roleId,
    'platformRoleIds' => [$platformRoleId], // optional — grant platform-subscription roles on acceptance
]);
Road::client()->invitations()->accept($invitationId);

// A membership carries the platforms its BU subscribes to
foreach (Road::client()->me()->businessUnits()->memberships as $m) {
    foreach ($m->platformSubscriptions as $sub) { /* $sub->platformId, $sub->scopeId */ }
}
// …or resolve one subscription directly by the platform's public id
$sub = Road::client()->businessUnits($buId)->subscriptions('plat_payment_gw');
Road::can('read', 'Invoice')->in($sub->scopeId); // check a platform-scoped permission

// The signed-in user's Eduzz products — Road calls Eduzz with the user's
// server-held token; iteration auto-paginates.
foreach (Road::client()->me()->eduzzProducts() as $product) {
    // $product->name, $product->payment->price['value'], …
}
// A 403 carrying EDUZZ_REAUTH_REQUIRED means the user must reconnect Eduzz;
// the code rides in the RoadAuthzException message + payload.

// IAM control plane
Road::client()->iam()->authorize([...]);
Road::client()->iam()->scope($scopeId)->roles()->all();
Road::client()->iam()->assignments()->create([...]);
```

Listings are **auto-paginating iterators** — `foreach` walks every page,
transparently following cursors. Need one bounded page? `->firstPage(limit: 50)`.
Prefer Laravel collection chaining? `->lazy()->filter(...)`. `include:` expands
related resources in one call (today via client-side fan-out, collapsing to a
single round-trip once the API ships native expand).

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
| `RoadConflictException` | 409 | `conflict` | Duplicate / version skew |
| `RoadValidationException` | 422 / 400 | `validation_error` | Carries `fieldErrors` keyed by field |
| `RoadRateLimitException` | 429 | `rate_limited` | Carries `retryAfter` (seconds) — never auto-retried |
| `RoadServerException` | 5xx | `server_error` | Retried with backoff (transient) |
| `RoadNetworkException` | 502 | `network_error` | Unreachable upstream — retried with backoff |
| `RoadApiException` | varies | varies | Catch-all for non-mapped statuses |

All errors are parsed from the API's RFC 7807 Problem Details and carry a stable
`code`, a `requestId`, and a `docs` URL.

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

## Service-to-service mode

For queued jobs, scheduled commands, and anything with no browser session,
`Road::asService()` returns a client that authenticates as the service principal
instead of the request user:

```php
Road::asService()->client()->businessUnits($buId)->members()->all();
```

Configure credentials in `.env` — either a shared secret (`client_credentials`)
or a signed assertion (`private_key_jwt`):

```dotenv
ROAD_SERVICE_MODE=client_credentials
ROAD_SERVICE_CLIENT_ID=...
ROAD_SERVICE_CLIENT_SECRET=...
# or: ROAD_SERVICE_MODE=private_key_jwt with ROAD_SERVICE_KEY_ID + ROAD_SERVICE_PRIVATE_KEY
```

The SDK acquires a token from the Auth Server, caches it (until just before
expiry, with a lock so concurrent workers don't stampede), and re-acquires
transparently on a 401. `asService()` uses a dedicated context, so a request
handler can call `Road::user()` *and* dispatch a job with `Road::asService()`
without cross-contamination.

## Webhooks

Opt in with `ROAD_WEBHOOKS_ENABLED=true` and set `ROAD_WEBHOOK_SECRET`. The SDK
mounts a single signed endpoint (default `POST /road/webhooks`, outside the
`web` group — no CSRF) that verifies the HMAC-SHA256 signature and dispatches
each delivery onto Laravel's event bus. Register ordinary listeners:

```php
use B1Road\Laravel\Webhooks\Events\MemberSuspended;

Event::listen(MemberSuspended::class, function (MemberSuspended $event) {
    // $event->id, $event->data->memberId, $event->data->businessUnitId
});
```

Every delivery also fires a catch-all `RoadWebhookReceived`. The endpoint
fails closed — `503` when no secret is configured, `401` on a bad signature —
and returns `200` for unknown event types (forward-compatible).

## Artisan commands

| Command | Purpose |
|---|---|
| `road:install` | Publish config + Inertia JS provider, append `.env` stubs |
| `road:doctor` | Connectivity + config smoke check (env, reachability, JWKS, clock skew, redirect_uri shape, session driver, middleware, proxy mount) |
| `road:whoami` | Print the session-stored user's claims |
| `road:generate-dtos` | Regenerate (or `--check`) the typed DTOs from the OpenAPI contract |

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
| `road.api.retry.*` | Transient-failure retries: `enabled`, `max_attempts` (3), `base_delay_ms` (250) |
| `road.token_store` | Where the BFF caches Auth Server tokens (`session`) |
| `road.inertia.enabled` | Inject `props.road` into Inertia shared props (default true) |
| `road.debug.header_enabled` | Surface `DecisionTrace` on errors when `X-Road-Debug: 1` |
| `road.service.*` | Service-to-service credentials for `Road::asService()` |
| `road.webhooks.*` | Webhook receiver: `enabled`, `path`, `secret`, `tolerance`, `verify` |

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
