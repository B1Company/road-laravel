# Changelog

All notable changes to `b1-road/laravel` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While Road serves the `alpha` API contract, this package stays pre-1.0 (`0.x`):
the surface may change between minor versions until the API graduates its
contract from `alpha` to `v1` (see
`docs/plans/done/14-sdk-publishing-and-versioning.md`).

## [Unreleased]

### Changed

- **The Inertia provider lives here alone.** `RoadInertiaProvider`
  (`resources/js/road-inertia-provider.tsx`, copied into your app by
  `road:install`) mirrored the npm package `@b1-road/laravel-react`, which was
  retired without ever being published. Its errors now start with
  `[b1-road/laravel]` instead of naming that package. To pick up the new copy,
  run `php artisan vendor:publish --tag=road-inertia --force`.

## [0.1.0-alpha.5] — 2026-10-05

### Changed

- **Breaking: `road.bridge` accepts one leg per route** (IR-073, plan 68).
  A fourth argument names it: `bridge` (default), `extensions` or `any`. A
  token from another leg is refused with `wrong_leg`, so the routes an
  installed extension's backend calls must say
  `road.bridge:<permission>,<tenant source>,,extensions`. An unknown leg throws
  a `LogicException` (a 500) instead of refusing every request.
- **A configured source makes its binding mandatory.** With a tenant source, a
  token naming no business unit is refused (`cross_tenant`); with an
  acting-user source, a token minted with no person present is refused
  (`cross_user`). A degraded answer is refused when either is configured, not
  only the tenant one.
- **`RoadException::$errorCode` carries the API's specific code when it sends
  one.** Road's problem bodies now include a `code` on refusals
  (`MEMBER_NOT_FOUND`, `PLATFORM_NOT_ACTIVE`, …), and the error mapper already
  preferred it, so on API v0.43.0 and later `errorCode` reads that code instead
  of the generic `not_found` / `conflict` / `validation_error` listed in the
  README. This is visible on alpha.4 as well, since it comes from the API.
  Branch on the exception class for the category and on `errorCode` for the
  specific case.

## [0.1.0-alpha.4] — 2026-09-25

### Security

- **Refuses a hosted Eduzz Plat surface reached over plaintext `http` (B1-643).**
  `http://api.plat.eduzz.com` used to pass as an unknown custom origin:
  `Environments::surfaceOf()` compared whole origins, so a real Road host over
  plaintext matched nothing, and every caller reads "matched nothing" as
  "someone else's gateway, none of my business". `HttpTransport` and
  `ProxyController` would then send bearer tokens to a real Road host in the
  clear.

  Recognition is by **host** now, and the match reports `secure`. `BootGuards`
  refuses a hosted surface without TLS before any other check. `http://localhost`
  still boots — it is nobody's hosted surface, and that is where the line sits.

  `Environments::surfaceOf()` returns `['environment' => …, 'surface' => …,
  'secure' => bool]` — a third key, if you were reading that array directly.

  Host matching also strips a terminal DNS dot. `parse_url` keeps it, and
  `api.plat.eduzz.com.` resolves to the same host — so the fully-qualified form
  read as an unknown origin and skipped this guard while the HTTP client went on
  to the real host with the bearer attached.

- **Raised the Guzzle floor to `^7.15.2` (B1-356).** The previous `^7.8`
  admitted versions carrying six advisories, the notable one being
  CVE-2026-69246 (high): a noncanonical host could bypass host-based checks.
  That matters here because Guzzle backs Laravel's HTTP client, which this
  package uses to fetch the Auth Server's JWKS — the trust anchor for token
  signature verification — as well as for OIDC discovery and every Road API
  call. Also covers CVE-2026-69245, CVE-2026-67353/67354/67355 and
  CVE-2026-67339 (cookie scoping, `Referer` leakage, response-cookie DoS, and
  `Proxy-Authorization` reaching origin servers).

  Consumers resolving `^7.8` today already pick up a patched 7.15.x, so this
  closes the door by constraint rather than leaving it to resolution order.

### Added

- **`road.bridge`: Platform Bridge enforcement on the provider side (B1-707).**
  A Laravel provider had nothing to check the brokered tokens other platforms
  present, so each one had to rebuild the cache, the tenant check and the fail
  mode by hand. `Route::middleware('road.bridge:read:Charge,buId')` now does
  what `bridgeEnforce()` does in the Node SDKs: asks Road about the token with
  your service credential, caches the answer per token (keyed by a digest of
  the whole token, never its `jti`), enforces the tenant and acting-user
  bindings, denies a missing permission with the same reason codes, attaches a
  `BridgeContext` to the request and reports the attempt after the response.
  A `bridge.grant.revoked` or `extension.install.uninstalled` webhook drops
  every cached answer. Also new: `Road::asService()->client()->bridge()` and
  the `BridgeAccessDenied` event. Configured under `road.platform_bridge`.

  Two defaults differ from Node on purpose. A cached answer never outlives the
  token's own expiry (Node can honour an expired token for up to a minute,
  B1-472). And `max_staleness` defaults to `0`, so a Road outage fails closed;
  set it to serve cached answers up to that age instead (Node defaults to
  300 seconds).

- **`ROAD_ENVIRONMENT` now reaches production on its own (B1-635).**
  `config/road.php` derives `road.api.base_url` from the environment, so going
  live is one variable instead of a URL copied into every deployment:

  ```dotenv
  ROAD_ENVIRONMENT=production   # ROAD_API_BASE_URL no longer needed
  ```

  Set `ROAD_API_BASE_URL` only to point at a local stack or your own gateway,
  and it is still required for `ROAD_ENVIRONMENT=local`, which Eduzz Plat does
  not host. The hosted URLs live in the new `B1Road\Laravel\Environments`,
  mirrored from `@b1-road/types` and checked against it in CI.

- **`road:install` asks which environment first**, and offers that
  environment's hosted API URL as the default — press enter to accept it. The
  URL question is no longer required for a hosted environment.

- **Typed webhook events for Platform Bridge and Platform Extensions
  (B1-458).** `BridgeGrantCreated`, `BridgeGrantRevoked`,
  `ExtensionInstallCreated` and `ExtensionInstallUninstalled`, with the
  `BridgeGrantWebhookData` and `ExtensionInstallWebhookData` payloads.
  `bridge.grant.*` and `extension.install.*` have been in the published
  catalog (`ROAD_WEBHOOK_EVENT_TYPES`) since plan 55 WS2, so a partner could
  already subscribe to them — but `RoadEventMap` mapped only the nine
  `organization.*`, so the delivery arrived with no DTO to read it and fell
  through to the untyped `RoadWebhookReceived`.

  `BridgeGrantWebhookData` carries **no** `businessUnitId`, and that absence
  is part of the contract rather than an omission: a grant is cross-platform,
  and a business unit on the payload would route it through the BU fan-out to
  every co-subscribed platform (B1-450).

- Regenerated the DTOs from a refreshed contract hub: 11 new Platform
  Extensions v2 types, plus `appUrl` on `CreatePlatformDto` and
  `UpdateOperationalDto`. The hub had drifted ~32 endpoints behind the API.
- **Platform Bridge DTOs, for the first time (B1-467).** `CreateGrantDto`,
  `CreateContractDto`, `PublishVersionDto`, `TokenExchangeRequestDto`,
  `BridgeAuthorizeDto` and `BridgeAttemptDto`. They were never generated because
  the contract hub's emitter stripped every `/bridge` path — a rule from when
  Bridge was gated out of all SDK surfaces, which outlived both the decision to
  publish Bridge and the decision to call it stable. `TokenExchangeRequestDto`
  keeps snake_case properties on purpose: that route is RFC 8693, not the
  camelCase `{ data }` contract.

### Changed

- **Three API changes reach the committed contract for the first time**, all of
  them already live on the server; nothing in this package changed for them, and
  they are recorded here because the contract is where a consumer would go
  looking. `GET /iam/authorization/scopes/lookup` no longer takes the
  `X-Road-Scope-Id` header and answers `404` instead of `400` (it authorizes the
  *resolved* scope now — the fix for a cross-tenant IDOR); and both
  `DELETE /iam/authorization/scopes/{scopeId}` and
  `DELETE …/scopes/{scopeId}/roles/{roleId}` answer `200` with cascade counts
  instead of `204`.

  Only the last one is reachable from this package, via
  `RoleCollection::delete()`. It is unaffected — the transport treats `200` and
  `204` alike and the in-memory test double already answered `200` — but
  `delete()` still returns `void`, so **the count the API now returns is
  discarded**. B1-449 surfaced it in `@b1-road/react` on the reasoning that
  external integrators cannot fix it themselves; that reasoning applies here too,
  and the PHP half is still open.

### Removed

- **`AdminCreateRoleDto`, `CreateAdminBuDto`, `CreateAdminUserDto`.** These were
  generated from schemas orphaned in the API's Swagger document and describe
  admin-only endpoints this package does not ship routes for, so no consumer
  could reach them. They were never documented as public API. Covered by the
  pre-1.0 surface clause above.

### Fixed

- **A new boot guard refuses a config that mixes the two environments.** Sandbox
  and production are separate instances with separate credentials, so a
  production app holding a sandbox issuer (or the reverse) cannot sign anyone
  in. It used to boot green and fail at the first real user's login with an OIDC
  error naming a client id; now it fails at boot naming which half is out of
  place. Silent for hosts it does not recognise — a local stack, a tunnel or a
  self-hosted Auth Server is nobody's business but yours.

- **`road:install --no-interaction` no longer writes a dead hostname into
  `.env`.** It stubbed `ROAD_API_BASE_URL=https://api.road.b1.app`, a host
  retired when `plat.eduzz.com` became canonical on 2026-09-10. It now stubs
  `ROAD_ENVIRONMENT=sandbox` and leaves the URL blank, so the config derives it
  and there is nothing left to go stale.

- **`composer.json` no longer advertises `https://portal.road.b1.app`** as the
  package homepage, support docs and author URL. That hostname never existed.

- `SendTestEventDto::$businessUnitId` is nullable, matching the API. It was
  required here while the API had already made it optional (B1-348), so the
  typed path rejected the *supported* call — omitting the business unit and
  letting Road resolve a subscriber itself. Caught by regenerating the hub, not
  by a test: nothing compares this package to a contract the hub has not been
  re-emitted from.
- The DTO generator no longer emits classes for schemas the contract does not
  reference. Previously `road:generate-dtos` also emitted internal Platform
  Bridge types into this published package, and threw outright on a placeholder
  schema named `Object` (a PHP reserved word).

## [0.1.0-alpha.3] — 2026-07-28

### Security

- **Open-redirect bypass with a control character (B1-329).** The guard added
  below rejected `//evil.example` and `/\evil.example` by inspecting the byte at
  index 1, so a control character *between* the slashes slipped past:
  `/\t/evil.example` (likewise `\n`, `\r`) was returned unchanged. Browsers strip
  those bytes before resolving a URL, so the victim still landed on the
  attacker's host after a genuine login. Control bytes are now rejected outright
  before the structural check — a legitimate in-app path never contains one —
  which also stops a CRLF reaching the `Location` header.

- **Open redirect in the OIDC login flow (CWE-601).**
  `AuthController::callback()` redirected to the session's `road.intended_url`
  verbatim, and `intended` is attacker-settable on the unauthenticated
  `GET /auth/road/login` (which stores it as-is). A crafted link like
  `…/auth/road/login?intended=https://evil.example/phish` therefore landed the
  victim on an attacker-controlled page immediately after a genuine login —
  Symfony's `RedirectResponse` emits the value in the `Location` header
  unmodified. The callback now honors only a same-origin absolute **path** (a
  single leading `/`, rejecting scheme-relative `//` and backslash `/\`
  variants) and falls back to `/` for anything else. The OIDC code was never
  exposed (the `redirect_uri` is the fixed config value); only the final landing
  redirect was affected. (Security review SDK-F1 / B1-303.)

## [0.1.0-alpha.2] — 2026-07-22

### Fixed

- Logout now revokes the access token at the Road API: `OidcProvider::logout()`
  POSTs `/iam/identity/me/logout` (best-effort) with the stored token before
  clearing the token store, so the JWT stops working at the Road API
  immediately instead of surviving until natural expiry. Note the semantics:
  the endpoint terminates **all** of the user's sessions (there is no
  per-session variant).

### Added

- `Road::client()->me()->logout()` — typed client method for the revocation
  endpoint, for custom sign-out flows.

## [0.1.0-alpha.1] — 2026-07-08

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
- **Laravel 13 support.** `illuminate/*` constraints widen to
  `^11.0|^12.0|^13.0`, and `web-token/jwt-framework` accepts `^3.3|^4.0` (v3 caps
  `brick/math` at `^0.12`, which Laravel 13 floors at `0.14.2`; v4 lifts the
  range). No SDK source changes were needed — the full suite is green on
  `laravel/framework` v13 (verified with `orchestra/testbench 11` + `pest 4`), and
  the existing 11/12 line is unaffected (`pest`/`pint`/`phpstan` all green with
  jwt-framework v4). CI now runs a `(8.3, ^13.0)` matrix leg alongside `^12.0`.
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

[Unreleased]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha.4...HEAD
[0.1.0-alpha.4]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha.3...road-laravel-v0.1.0-alpha.4
[0.1.0-alpha.3]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha.2...road-laravel-v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha.1...road-laravel-v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/B1Company/road/compare/road-laravel-v0.1.0-alpha...road-laravel-v0.1.0-alpha.1
[0.1.0-alpha]: https://github.com/B1Company/road/releases/tag/road-laravel-v0.1.0-alpha
