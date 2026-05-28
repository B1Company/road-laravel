<?php

declare(strict_types=1);

/**
 * Minimal stub for `\Inertia\Inertia` so the SDK's auto-mount
 * `class_exists()` probe resolves true in tests that exercise the
 * Inertia integration path. Real consumer apps depend on the actual
 * `inertiajs/inertia-laravel` package — this stub only exists to make
 * the class-existence guard returns true without pulling Inertia as a
 * test dep.
 *
 * Captures `share()` calls into a static array so tests can assert
 * what got shared without needing a real Inertia response.
 *
 * Side-effect warning: once this file is included, `\Inertia\Inertia`
 * is permanently defined for the rest of the test process. Don't
 * include it from tests that need to verify the "Inertia not
 * installed" branch.
 */

namespace Inertia;

if (! class_exists(__NAMESPACE__.'\\Inertia', false)) {
    class Inertia
    {
        /** @var array<string,mixed> */
        public static array $shared = [];

        public static function share(string $key, mixed $value): void
        {
            self::$shared[$key] = $value;
        }

        public static function reset(): void
        {
            self::$shared = [];
        }
    }
}
