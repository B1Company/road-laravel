<?php

declare(strict_types=1);

/**
 * The Inertia bridge has one source of truth: the npm package
 * `@b1-road/laravel-react` (`apps/sdks/road-laravel-react/src/index.tsx`). The
 * Laravel SDK ships a byte-identical copy at
 * `resources/js/road-inertia-provider.tsx` so `vendor:publish --tag=road-inertia`
 * hands integrators the exact same, maintained implementation.
 *
 * This guard fails if the two drift. If you intentionally change the bridge,
 * edit the npm package's `src/index.tsx` and copy it verbatim into the SDK's
 * `resources/js/road-inertia-provider.tsx` (or vice-versa) so both stay equal.
 */
it('ships a vendor:publish bridge byte-identical to the @b1-road/laravel-react source', function () {
    $shipped = __DIR__.'/../../resources/js/road-inertia-provider.tsx';
    $canonical = __DIR__.'/../../../road-laravel-react/src/index.tsx';

    expect(file_exists($shipped))->toBeTrue("Missing vendor:publish bridge: {$shipped}");

    // The canonical npm source lives in a sibling package; only assert parity
    // when it's present (it always is in the monorepo; a standalone checkout of
    // just the Composer package won't have it, and shouldn't fail there).
    if (! file_exists($canonical)) {
        expect(true)->toBeTrue();

        return;
    }

    expect(hash_file('sha256', $shipped))
        ->toBe(
            hash_file('sha256', $canonical),
            "The SDK's vendor:publish bridge (resources/js/road-inertia-provider.tsx) has "
            .'drifted from the canonical @b1-road/laravel-react source. Copy one over the '
            .'other so both are byte-identical — the bridge has a single source of truth.'
        );
});
