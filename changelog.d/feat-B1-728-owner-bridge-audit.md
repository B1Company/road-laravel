### Added

- **`Road::client()->bridge()->audit($platformId, 'inbound'|'outbound')`**
  (IR-069, plan 68). The platform owner's own Bridge audit: token exchanges,
  checks, attempts and grant changes the platform took part in, as
  `BridgeAuditEntry` rows that name the other platform and the decision.
  Iterating walks every page; `firstPage()` returns one. It needs the owner's
  user token, so the service credential gets 403.
