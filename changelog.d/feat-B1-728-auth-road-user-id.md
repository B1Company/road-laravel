### Fixed

- **`Road::client()->me()->get()` reads `/me/profile`.** It asked
  `/iam/identity/me`, a route the API no longer serves, so the call failed
  with a 404 against every live Road. The test backend answers the new path.

### Added

- **`Road::roadUserId()`** (F4.6, D18 in plan 68). `Road::userId()` is the
  Auth Server user id, while Bridge (`onBehalfOfUser`, the person a provider
  route names), IAM subjects and webhook payloads speak the Road user id. The
  new method reads `/me/profile` the first time a session asks and keeps the
  id in the session's `TokenSet` (a refresh carries it over), so later
  requests make no call. When Road cannot be read it throws the client's
  exception rather than return the wrong id. `actingAsRoadUser()` seeds it.
