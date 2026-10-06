### Added

- `UpdateDeveloperOperationalDto`, the body of the developer route
  `PATCH /developer/platforms/{id}/operational`. It has every field of
  `UpdateOperationalDto` except the login client id, which Road sets itself.
