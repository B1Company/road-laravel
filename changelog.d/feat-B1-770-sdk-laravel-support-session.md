### Added

- **`Road::supportSession()`** (plan 69, B1-770). `true` when an Eduzz support agent opened the session on the person's behalf. Read from Road (`/me/session`, with the ID token's `sid`) the first time a session asks, then kept with its tokens across refresh. `null` means no one is signed in or Road could not answer yet. Inertia pages receive it as `road.user.supportSession`. Test mode: `actingAsRoadUser($id, supportSession: true)` and `RoadScenario::withUser($id, supportSession: true)`.
