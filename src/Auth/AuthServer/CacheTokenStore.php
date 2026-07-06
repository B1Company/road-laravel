<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Session\Session;

/**
 * Token store backed by Laravel's cache (Redis in production) instead of the
 * session payload. Keyed by session id — `road:session:{id}` — with a TTL equal
 * to the session lifetime, so abandoned logins expire server-side without a
 * sweeper.
 *
 * Prefer this over {@see SessionTokenStore} for horizontally-scaled BFFs and
 * Octane/FrankenPHP: the tokens live in a shared store all workers read, rather
 * than in a per-worker session payload. The session cookie still identifies the
 * user; only where the tokens are *kept* changes. Select it with
 * `config('road.token_store') === 'cache'`.
 *
 * Mirrors @b1-road/nestjs's redis store (same `road:session:{id}` scheme).
 */
final class CacheTokenStore implements TokenStore
{
    private const KEY_PREFIX = 'road:session:';

    public function __construct(
        private readonly Cache $cache,
        private readonly Session $session,
        /** Session lifetime in seconds — the cache row TTL. */
        private readonly int $ttlSeconds,
    ) {}

    public function get(): ?TokenSet
    {
        $raw = $this->cache->get($this->key());
        if (! is_array($raw)) {
            return null;
        }

        return TokenSet::fromArray($raw);
    }

    public function put(TokenSet $tokens): void
    {
        // Ensure the session id is minted before we key on it.
        $this->session->save();
        $this->cache->put($this->key(), $tokens->toArray(), $this->ttlSeconds);
    }

    public function clear(): void
    {
        $this->cache->forget($this->key());
    }

    private function key(): string
    {
        return self::KEY_PREFIX.$this->session->getId();
    }
}
