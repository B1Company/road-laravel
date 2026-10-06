# Changelog fragments (b1-road/laravel)

Same rules as the root `changelog.d/README.md`: a pull request adds one file
here, named after its branch, instead of editing `../CHANGELOG.md`. The release
PR folds them in with `node scripts/changelog-fragments.mjs compile` before it
cuts the `## [<version>]` heading the Laravel tag workflow looks for.
