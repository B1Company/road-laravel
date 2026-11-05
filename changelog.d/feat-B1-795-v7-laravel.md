### Changed

- **Breaking: the SDK now speaks the `authz/*` API** (plan 75, B1-795). The old `/iam/authorization/*` routes answer `410`, so upgrade before they reach your environment. Ids are public: `role_…`, `perm_…`, `asg_…`, `scope_…`.
- **Breaking: if you published `config/road.php`, edit `road.proxy.allow` by hand** (B1-795). Replace `iam/authorization/*` with `authz/*`. Your copy overrides the package default, and until it changes the proxy answers `404` for every `authz/*` path.
- **Breaking: the target of a check picks the catalog, so your platform's codes need `forPlatform()`** (plan 75, N10). `in($buId)` still means the business unit, where Road's catalog answers, and your own code now reads `denied_unknown_code` there. Point it at the unit's subscription instead: `Road::can('read', 'Invoice')->forPlatform()->in($buId)`, `road.permission:read,Invoice,platform:buId`, `#[RequirePermission(..., forPlatform: true)]`, or the Gate ability `road:platform:read:Invoice`. `Road::canMany([...])->forPlatform()->in($buId)` moves a whole batch. `forPlatform()` reads `ROAD_PLATFORM_ID` or takes an id, and throws before any request with neither. A business unit with no such subscription throws `RoadNotFoundException`. `inScope($scopeId)` names a scope, and throws beside `forPlatform()`.
- **Breaking: a check by business unit makes one request, and `in()` takes only a business unit id** (B1-795). There is no lookup first. To pass a scope id, use `inScope()`.
- **Breaking: `iam()->check()` and `iam()->explain()` replace `authorize()` and `authorizeBatch()`** (B1-795). `check()` takes `codes` and a scope, and answers a `CheckResult` of `CheckDecision`. `Can::result()` is a `CheckDecision`. `AuthorizeResult`, `AuthorizeBatchResult` and `AuthorizeBatchEntry` are gone.
- **Breaking: `Role`, `Scope`, `Assignment` and `EffectivePermissionsResult` follow the new wire** (B1-795). `Role` drops `permissions` and `isSystem`, and gains `audience`, `grantsAll`, `custom`, `scopeId`, `permissionCount` and `updatedAt`. Its `description` may be `null`. `Scope` gains `catalogScopeId` and drops `metadata`, `parentScope` and `updatedAt`. `EffectivePermissionsResult` is `{ scopeId, all, codes }`. `RoleWire` and `ParentScopeRef` are gone.
- **Breaking: roles and assignments moved** (B1-795). `roles()->create()` takes `permissionIds` (`perm_…`) or `cloneFromRoleId`, and `roles()->delete()` returns how many assignments ended. `roles()->get()` and `roles()->permissions()` read from the collection's scope, so a role that scope does not offer is a `RoadNotFoundException`. Assign with `iam()->scope($id)->assign()`. `iam()->assignments()->list()` takes no scope and returns a paged collection.
- **Breaking: webhook payloads match what the API delivers** (B1-795). `BridgeGrantWebhookData` carries `roleId` and `roleName` instead of `roleTemplateName`. `ExtensionInstallWebhookData` carries `grantedPermissions` instead of `grantedScopes`.
- **`Road::assert()` names the code and the engine's reason in its message** (B1-795).
- **`Road::fake()` answers like the engine** (B1-795): one catalog per scope, its reason strings, codes matched in any case, the `400` for a field the API no longer takes, and a `404` for a missing subscription. A BU's catalog is the list Road declares for business units, so `read:BusinessUnit`, declared at system level, reads `denied_unknown_code` there and a role passes only by holding a declared code. Fake role ids are now `role_<name>`.
- **Breaking for test suites: move platform codes in a scenario from `withRole()` to `withSubscription()`** (B1-795), set `road.platform_id`, and check them with `forPlatform()`. A BU role answers for Road's codes only, as on the API, so a check for `read:Project` that a BU role used to pass now reads `denied_unknown_code`.

### Added

- **A warning in your log on `denied_unknown_code`** (B1-795). It names the target and what to try, since the code may belong to another catalog. The decision stays the same.
- **`RoadScenario::withSubscription()`** (B1-795). Declare a platform's roles and who holds them at a business unit's subscription, so a test proves your own permissions allow and deny.
- **`iam()->scope($id)->permissions()`, `assignments()`, `assignMany()`, and `roles()->permissions()`, `compose()`, `decompose()`** (plan 75, N23, N26). What a custom role needs. `assignMany()` is atomic.
- **`MyPermissions::$byScope`** (plan 75, N4). `me()->permissions()` now covers each subscription, not only business units. `*` stands for a role that holds every code at the scope.
- **Typed events for `authz.assignment.created`, `authz.assignment.revoked`, `authz.role.changed` and `authz.catalog.changed`** (B1-795). Listen to them if you cache authorization answers.

### Removed

- **`iam()->scopes()->create()`** (plan 75, N12). Scopes are born from domain actions, so the API has no such route.

### Fixed

- **An answer with no verdict now throws `RoadApiException`** (plan 75, N9). It used to read as a denial, which made a failure look like a decision.
