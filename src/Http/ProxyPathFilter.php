<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http;

/**
 * Decides whether a candidate proxy path is safe to forward upstream.
 *
 * Two stages:
 *   1. Normalize: reject path traversal segments (`..`, `.`), double
 *      slashes, backslashes, and control characters (NUL / CR / LF —
 *      header-injection surface).
 *   2. Match against the glob allowlist (`organization/*` etc.).
 *
 * Extracted from ProxyController so the normalizer is unit-testable
 * without going through Symfony route dispatch (which canonicalizes
 * `..` segments before they reach the controller).
 */
final class ProxyPathFilter
{
    /**
     * @param  list<string>  $allowPatterns  e.g. `['organization/*', 'iam/identity/*']`
     */
    public function __construct(private readonly array $allowPatterns)
    {
    }

    public function isAllowed(string $path): bool
    {
        $candidate = $this->normalize($path);
        if ($candidate === null) {
            return false;
        }

        if ($this->allowPatterns === []) {
            return true;
        }

        foreach ($this->allowPatterns as $pattern) {
            if ($this->matches($pattern, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the cleaned candidate (no leading slash) or null when the
     * input is unsafe.
     */
    public function normalize(string $path): ?string
    {
        $candidate = ltrim($path, '/');

        // Control characters (NUL / CR / LF / etc.) and backslashes.
        if (preg_match('/[\\x00-\\x1f\\\\]/', $candidate) === 1) {
            return null;
        }
        if (str_contains($candidate, '//')) {
            return null;
        }

        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return null;
            }
            // %2e%2e (URL-encoded `..`) — defense in depth even if
            // routing decoded it for us upstream.
            if (strcasecmp($segment, '%2e%2e') === 0 || strcasecmp($segment, '%2e') === 0) {
                return null;
            }
        }

        return $candidate;
    }

    private function matches(string $pattern, string $candidate): bool
    {
        $regex = '#^'.str_replace('\\*', '.*', preg_quote($pattern, '#')).'$#';

        return (bool) preg_match($regex, $candidate);
    }
}
