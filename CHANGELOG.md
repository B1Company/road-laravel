# Changelog

All notable changes to `b1-road/laravel` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While Road serves the `alpha` API contract, this package stays pre-1.0 (`0.x`):
the surface may change between minor versions until the API graduates its
contract from `alpha` to `v1` (see
`docs/plans/done/14-sdk-publishing-and-versioning.md`).

## [Unreleased]

### Fixed
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
