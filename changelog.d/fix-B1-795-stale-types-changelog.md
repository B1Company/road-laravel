### Removed

- **Breaking: generated DTOs for retired routes** (B1-795). `AuthorizeDto`, `CreateGrantDto`, `CreateScopeDto` and `RevokeAssignmentDto` are gone, and `UpdateIdentityDto` no longer carries the catalog fields. Use `iam()->check()` in place of `AuthorizeDto`, and declare a catalog with the import route. To revoke, list the subject's assignments, find the one with that role and scope, and delete it by id. `CreateGrantDto` gives way to an approved connection request. `CreateScopeDto` has no successor: a scope is created with what it belongs to.
