### Added

- **`ExtensionSessionVerifier` checks the embed session context** (IR-026,
  plan 68). An extension's backend hands it what its iframe forwarded (`signed`
  from `useExtensionHost` in `@b1-road/react/extension`) and gets back an
  `ExtensionSession`: the Road user id, the install, the extension and the
  business unit. A refusal is a `RoadExtensionSessionException` (401) with the
  same codes as the Node SDKs: `malformed`, `signature_mismatch`,
  `unsupported_version`, `expired`, `not_yet_valid`, `install_mismatch`. The
  HMAC is compared in constant time, 30 seconds of clock skew are tolerated,
  and `expectInstall:` / `maxAgeSeconds:` tighten the check. Tested against
  `SESSION_CONTEXT_SIGNING_VECTOR` from `@b1-road/types/extensions`, so no
  hand-rolled HMAC is needed.
