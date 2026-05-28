<?php

declare(strict_types=1);

/*
 * Doctor checks that DON'T require the Inertia stub. Filename starts
 * with "D" so Pest runs it before "I"nertiaAutoMountTest pulls in the
 * stub — once that stub loads, `\Inertia\Inertia` is defined for the
 * rest of the process and the "Inertia not installed" branch becomes
 * unreachable. Keep this file Inertia-free.
 */

it('reports that Inertia is not installed when the package is absent', function () {
    expect(class_exists(\Inertia\Inertia::class, false))->toBeFalse();

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Inertia not installed — skipping shared-props check');
});
