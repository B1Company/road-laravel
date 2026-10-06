### Changed

- **The Inertia provider lives here alone.** `RoadInertiaProvider`
  (`resources/js/road-inertia-provider.tsx`, copied into your app by
  `road:install`) mirrored the npm package `@b1-road/laravel-react`, which was
  retired without ever being published. Its errors now start with
  `[b1-road/laravel]` instead of naming that package. To pick up the new copy,
  run `php artisan vendor:publish --tag=road-inertia --force`.
