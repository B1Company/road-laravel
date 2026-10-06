### Fixed

- **Packagist links that worked only inside B1** (N16, plan 68).
  `support.issues` and `support.source` pointed at Road's private monorepo, a
  404 for everyone else. `support.source` is now the public mirror,
  `B1Company/road-laravel`, and `support.email` (`contato@b1.app`) replaces
  `support.issues`.
