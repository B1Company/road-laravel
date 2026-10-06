### Added

- **`bridge()->exchangeForUser($audience, $scope, $businessUnitId)`** (F4.1,
  plan 68). Inside a `road`-protected route it asks Road for the presence
  assertion with the signed-in person's session, then exchanges your service
  credential for a token audienced at the provider, and returns the OAuth
  payload. `bridge()->presenceAssertion($businessUnitId)` is the first step
  alone. A refusal throws `RoadBridgeExchangeException`, whose `errorCode()` is
  Road's code (`BU_NOT_SUBSCRIBED_TO_PLATFORM`, …) and whose `description` says
  the next step; a missing service credential, platform id or person throws
  `RoadBridgeSetupException` before anything is sent. New config key
  `road.platform_id`, from `ROAD_PLATFORM_ID`.
