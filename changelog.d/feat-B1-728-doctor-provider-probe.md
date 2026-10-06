### Added

- **`road:doctor` checks Bridge provider readiness, with nobody signed in**
  (F7.2 in plan 68). With a service credential configured, it gets a service
  token and asks `POST /bridge/authorize` about a token that cannot be real:
  `UNKNOWN_PROVIDER` fails (the credential belongs to no platform),
  `PROVIDER_NOT_HOMOLOGATED` warns (the platform is not homologated as a
  provider; a consumer-only app never is), and `INVALID_BROKERED_TOKEN` passes.
  Without a service credential the probe is skipped and fails nothing. Neither
  the credential nor the token is printed. Same checks and wording as
  `npx road doctor`.
- **`road:doctor` prints the environment and the Road API base it resolved**
  (`Environment: production`, `Road API: https://api.plat.eduzz.com — the
  hosted URL for production`), so a run says which instance it probed.
- A failed service-token request's `RoadAuthnException::payload()` now carries
  the token endpoint's `status`.
