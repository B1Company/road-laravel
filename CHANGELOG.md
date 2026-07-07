# Changelog

All notable changes to `b1-road/laravel` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While Road serves the `alpha` API contract, this package stays pre-1.0 (`0.x`):
the surface may change between minor versions until the API graduates its
contract from `alpha` to `v1` (see
`docs/plans/done/14-sdk-publishing-and-versioning.md`).

## [Unreleased]

### Added
- **Laravel 13 support.** `illuminate/*` constraints widen to
  `^11.0|^12.0|^13.0`, and `web-token/jwt-framework` accepts `^3.3|^4.0` (v3 caps
  `brick/math` at `^0.12`, which Laravel 13 floors at `0.14.2`; v4 lifts the
  range). No SDK source changes were needed — the full suite is green on
  `laravel/framework` v13 (verified with `orchestra/testbench 11` + `pest 4`), and
  the existing 11/12 line is unaffected (`pest`/`pint`/`phpstan` all green with
  jwt-framework v4). CI now runs a `(8.3, ^13.0)` matrix leg alongside `^12.0`.

### Fixed
- **The production boot guard no longer fires during Composer's
  `package:discover` (and other console/build commands).** A deploy that runs
  `composer install` with `APP_ENV=production` but before the OIDC secrets are
  injected would fatal at the post-autoload `package:discover` step, aborting the
  build (the guard threw at provider boot). The guard now runs only for
  non-console boots (`! runningInConsole()`) — it still fires on the HTTP path it
  exists to protect (a silent 401/500 in prod), and it no longer blocks
  `php artisan road:doctor`, the very tool meant to diagnose the misconfig, from
  running in a misconfigured app. Console/queue paths fail loud at the call site
  anyway (a scheme-less base URL throws on first use). Verified against a real
  deploy simulation (fresh app + `APP_ENV=production` + `composer install`).
- **`road:doctor` no longer reports a broken cache store as an Auth-Server
  failure.** Discovery + JWKS cache through Laravel's cache repository, so a
  broken `CACHE_STORE` (e.g. the `database` store with no migrated `cache` table,
  the default on a fresh app) surfaced its storage error *as* the discovery/JWKS
  verdict — even though the network fetch worked (the uncached clock-skew check to
  the same issuer stayed green, a confusing split). The doctor now preflights the
  cache store as its own check; if it's down, discovery/JWKS report "skipped —
  cache unavailable" instead of blaming the network.
- **The `/road-api` proxy route now runs in the `web` middleware group.** It was
  mounted with only `road.errors` + `road`, so `StartSession` never ran and the
  session-backed token store was empty on every proxied call — each one 401'd
  and `@b1-road/react` cookie-mode widgets span in an `onUnauthenticated` redirect
  loop. (The proxy tests seed the session through the harness, so they never
  caught it; the Beacon demo in a real browser did.) `web` also brings CSRF
  (the React client sends the `XSRF-TOKEN` header) and cookie encryption.
- **`Road::can()` / `canMany()` no longer forward a `subjectId`** to
  `/iam/authorization/authorize[/batch]`. The BFF holds the caller's access
  token, not their Road user id; forwarding the Auth Server `sub` made the API
  403 every check ("you may only query your own authorization") because IAM keys
  subjects by the Road profile id, not the `sub`. The token already identifies
  the caller — now matches `@b1-road/nestjs` (which fixed this as field-report
  A1). Surfaced by the Beacon demo running against the live sandbox Auth Server.
- **Proxy allowlist now covers the caller's own `me/*` endpoints**
  (`me/business-units`, `me/profile`, `me/permissions`). The default allowlist
  shipped only `organization/*` + `iam/*`, so `@b1-road/react` widgets running in
  cookie mode (e.g. `<BusinessUnitSwitcher>` via `useMyBusinessUnits`) 404'd
  through the proxy. Surfaced by the Beacon demo (the first real cookie-mode
  consumer).

### Added
- **`Subject|string` / `Action|string` parity with `@b1-road/nestjs`** across
  `Road::can()`, the `#[RequirePermission]` attribute, and the `road.permission`
  middleware. Platform-defined subjects outside Road's core algebra (e.g.
  `'Project'`) now gate the same way they do in the Nest SDK
  (`#[RequirePermission(Action::Read, 'Project', in: 'buId')]`,
  `Road::can(Action::Create, 'Project')`, `road.permission:create,Project,buId`)
  — no `->raw()` escape hatch needed. Backward compatible: the canonical
  `Action`/`Subject` enums keep working unchanged.
- Landed the OpenAPI contract hub (`apps/sdks/contract/openapi.json`, emitted
  from the API's public Swagger doc) and generated the typed input DTOs into
  `src/DTO/Generated/`. `road:generate-dtos --check` now runs as a test, so the
  SDK's view of the API input contract can't drift silently.
- **Platform-scope model.** New `PlatformRef`, `ScopedRoleRef`, and
  `PlatformSubscriptionRef` DTOs; `Membership` now carries `platformSubscriptions`
  (the wire always sends it). Mirrors `@b1-road/types`.
- **Member role assignment.** `members()->assignRole($memberId, $roleId)` /
  `revokeRole(...)` on the client, matching `@b1-road/nestjs`.
- **Invitation `platformRoleIds`.** The invitation-create input forwards the
  optional `platformRoleIds` (grant platform-subscription roles on acceptance).
- **Platform-subscription resolver.** `businessUnits($buId)->subscriptions($platformPublicId)`
  → `PlatformSubscriptionResolution` (turns a platform public id into its IAM
  scope id).
- **Eduzz products.** `me()->eduzzProducts()` — an auto-paginating collection of
  `EduzzProduct` with a `firstPage()` hatch; the `EDUZZ_*` error codes ride the
  thrown exception's 7807 `detail`.
- **`me()->memberships()` and `me()->platformRoles($platformId, $buId)`** for
  full `me` parity with the NestJS SDK.
- **Gate bridge (opt-in).** `road.bridges.gate` routes `road:{action}:{Subject}`
  abilities through Road, so `Gate::allows`, `$user->can`, and Blade `@can`
  answer via Road.
- **Boot-time production guards.** In production, provider boot throws on a
  scheme-less `ROAD_API_BASE_URL`, missing OIDC credentials, a non-persistent
  session driver, or webhooks enabled without a secret. No-op outside production.
- **`cache` token-store driver.** `road.token_store = cache` keeps BFF tokens in
  the cache (Redis) keyed by session id (TTL = session lifetime) instead of the
  session payload, for horizontally-scaled / Octane BFFs.
- **Escape hatches.** `Road::client()->request(...)` (raw call to an unmodelled
  endpoint) + `transport()`, and `Road::asUser($token)` (act as a user whose
  token you already hold).
- **Interactive `road:install`.** Prompts for the four uninferrable env values,
  derives the redirect URI from `APP_URL`, and offers to run `road:doctor`.
  `--no-interaction` preserves the stub-append behavior.

### Security
- **The 403 decision trace no longer leaks by default.** The `DecisionTrace`
  (including the grants the caller *holds*) is attached to a 403 body only when
  the caller sends `X-Road-Debug: 1` (or `?debug=road`) **and** the debug header
  is enabled (auto-on outside production). Previously it rode every 403 in all
  environments. The message still names the *required* permission (intended DX,
  parity with `@b1-road/nestjs`).

## [0.1.0-alpha] — 2026-06-11

### Added

- **True BFF authentication** against the Auth Server: OIDC login (PKCE),
  callback, and logout, with the access/refresh/ID tokens held server-side and
  never exposed to the browser. Silent refresh-rotation on expiry.
- **Auth integration**: the `road` and `road.optional` middleware, the
  `auth('road')` guard, and the `RoadUser` value object.
- **Authorization primitives**: `Road::can()`, `Road::canMany()`,
  `Road::assert()`, the `road.permission:` middleware, and the
  `#[RequirePermission]` / `#[SkipAuthorization]` PHP attributes — one
  enforcement path, with a structured `DecisionTrace`.
- **Server-side client** (`Road::client()`) at parity with `@b1-road/nestjs`:
  - `me()` — profile, business units, effective permissions.
  - `businessUnits()` — `get()` (with `include: ['members','roles']` expansion),
    `create()`/`update()`, and the `businessUnits($id)` navigator scope.
  - `businessUnits($id)->members()` / `->roles()` / `->invitations()` — auto
    paginating iterators (`foreach`), with `firstPage()`, `all()`, and `lazy()`.
  - `iam()` — `authorize()`, `authorizeBatch()`, `scope()`, `scopes()`,
    `assignments()`, and effective-permission queries.
  - `invitations()` — accept / reject by id.
- **BFF proxy** at `/road-api/{any?}` with a glob allowlist and path-traversal
  rejection, so `@b1-road/react` widgets work with no frontend JWT.
- **Inertia integration**: an auto-mounted `ShareRoadContext` shares `props.road`.
- **Webhooks** (opt-in): a signed-delivery endpoint with HMAC-SHA256
  verification (fail-closed) that dispatches typed Laravel events
  (`MemberSuspended`, `InvitationCreated`, …) plus a catch-all
  `RoadWebhookReceived`.
- **Service-to-service mode**: `Road::asService()` (client_credentials /
  private_key_jwt) for jobs and cron, with a cached, lock-serialised token
  store and transparent re-acquire on a 401.
- **Errors**: the eight-class taxonomy (`RoadAuthn`/`Authz`/`NotFound`/
  `Conflict`/`Validation`/`RateLimit`/`Server`/`Network` + the `RoadApi`
  catch-all), parsed from the API's RFC 7807 Problem Details, each carrying a
  stable code, request id, and docs URL.
- **HTTP transport hardening**: idempotency keys on mutations, retries for
  transient failures (5xx + network) with exponential backoff + jitter, and
  `Retry-After` handling on 429.
- **Test harness**: `Road::fake()`, `RoadScenario`, `ActsAsRoadUser`, and
  `RoadFakeAssertions` — no Auth Server, no JWKS, no HTTP.
- **Telemetry** hook (`RoadTelemetry`) with a no-op default and a shared event
  shape across the Road SDKs.
- **Tooling**: `road:install`, `road:doctor`, `road:whoami`, and
  `road:generate-dtos` (OpenAPI-contract codegen with a `--check` drift gate).

[Unreleased]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha...HEAD
[0.1.0-alpha]: https://github.com/B1Company/road/releases/tag/road-laravel-v0.1.0-alpha
