### Removed

- **Breaking: generated DTOs for retired routes** (B1-795). `AuthorizeDto`, `CreateGrantDto`, `CreateScopeDto` and `RevokeAssignmentDto` are gone, and `UpdateIdentityDto` no longer carries the catalog fields. Use `iam()->check()`, and declare a catalog with the import route.
