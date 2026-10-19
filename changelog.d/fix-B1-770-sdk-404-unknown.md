### Changed

- **`Road::supportSession()` returns `null` on a `/me/session` 404 or a missing `sid`** (plan 69, B1-770), and keeps nothing, instead of settling on `false`: a stale or wrong session id must never read as "not support".
