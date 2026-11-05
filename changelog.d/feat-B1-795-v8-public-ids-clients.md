### Changed

- **Scope ids are `scope_…`** (B1-795, plan 75). `inScope()` and every DTO treat them as opaque strings, so nothing changes unless you stored a scope UUID. Read it again from the API.
