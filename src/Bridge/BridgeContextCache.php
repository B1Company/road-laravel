<?php

declare(strict_types=1);

namespace B1Road\Laravel\Bridge;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * Per-token cache of Bridge authorization contexts, in Laravel's cache store so
 * every worker shares it (the Node SDK keeps it in process memory; PHP has no
 * process that outlives a request).
 *
 * Two rules carry the security weight here:
 *
 * - **The key is a digest of the whole token**, never its `jti`. A warm hit
 *   returns a context without Road seeing the token, and the `jti` is an
 *   unverified claim that survives log redaction and sits in every audit row.
 *   Keyed on it, anyone who learned a warm `jti` could present `alg:none`
 *   garbage carrying it and be served the real token's grants. Hashed so the
 *   raw bearer never sits in the cache store.
 * - **No entry outlives the token.** An entry stops being usable at the
 *   earlier of Road's `expiresAt` and the token's own `exp`, whatever the TTL
 *   says. The Node middleware does not do this yet (B1-472) and honours an
 *   expired token for up to a minute; this one never does, not even while
 *   serving stale during an outage.
 *
 * A grant revocation flushes every entry at once ({@see flush()}). The
 * `bridge.grant.revoked` payload names the consumer platform, not the tokens
 * minted under it, so there is no narrower set to evict. Revocations are rare
 * and a flush only costs one Road call per token on its next request.
 */
final class BridgeContextCache
{
    private const PREFIX = 'road:bridge:';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {}

    public static function keyFor(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The current cache generation. A flush replaces it, which orphans every
     * entry stored under the old one.
     *
     * **Capture it once, before asking Road, and pass it to every read and
     * write for that request.** A revocation can land while Road is still
     * answering. Resolving the generation at write time would file that
     * pre-revocation answer under the new generation, where the next request
     * reads it, and the revoked grant would keep working until the TTL ran out.
     * Written under the captured generation instead, a stale answer lands where
     * nobody reads it (and {@see put()} drops it outright).
     */
    public function generation(): string
    {
        $generation = $this->store()->get(self::PREFIX.'generation');

        return is_string($generation) ? $generation : '0';
    }

    /**
     * @return array{context: BridgeContext, fetchedAt: float, usableUntil: float|null}|null
     */
    public function get(string $key, string $generation): ?array
    {
        $entry = $this->store()->get($this->contextKey($key, $generation));
        if (! is_array($entry) || ! is_array($entry['context'] ?? null) || ! is_float($entry['fetchedAt'] ?? null)) {
            return null;
        }

        try {
            $context = BridgeContext::fromWire($entry['context']);
        } catch (\UnexpectedValueException) {
            return null;
        }

        $usableUntil = $entry['usableUntil'] ?? null;

        return [
            'context' => $context,
            'fetchedAt' => $entry['fetchedAt'],
            'usableUntil' => is_float($usableUntil) ? $usableUntil : null,
        ];
    }

    /**
     * Store a freshly fetched context. Kept only as long as the longest bound
     * that could still read it (freshness or staleness), and never past the
     * token's expiry. A token that is already expired is not stored at all,
     * and neither is an answer fetched before the latest flush.
     */
    public function put(string $key, BridgeContext $context, string $token, float $now, string $generation): void
    {
        if ($generation !== $this->generation()) {
            return;
        }

        $usableUntil = self::usableUntil($context, $token);
        $keep = max($this->seconds('read_ttl', 60), $this->seconds('write_ttl', 5), $this->seconds('max_staleness', 0));
        if ($usableUntil !== null) {
            $keep = min($keep, (int) floor($usableUntil - $now));
        }
        if ($keep <= 0) {
            return;
        }

        // Still keyed on the captured generation: if a flush lands between the
        // check above and this write, the entry is orphaned rather than served.
        $this->store()->put($this->contextKey($key, $generation), [
            'context' => $context->toWire(),
            'fetchedAt' => $now,
            'usableUntil' => $usableUntil,
        ], $keep);
    }

    /**
     * Remember that Road refused to characterise this token broadly (its 422),
     * so later requests go straight to the one question it can answer. This is
     * the phrasing to use, never a verdict. The verdict itself stays uncached.
     * Scoped to the generation like a context, so a flush clears it too.
     */
    public function markDegraded(string $key, string $token, float $now, string $generation): void
    {
        $exp = self::tokenExp($token);
        $keep = $exp !== null ? (int) floor($exp - $now) : $this->seconds('read_ttl', 60);
        if ($keep > 0) {
            $this->store()->put(self::PREFIX.'degraded:'.$generation.':'.$key, true, $keep);
        }
    }

    public function isDegraded(string $key, string $generation): bool
    {
        return $this->store()->get(self::PREFIX.'degraded:'.$generation.':'.$key) === true;
    }

    /** Drop every cached context. Wired to `bridge.grant.revoked` and `extension.install.uninstalled`. */
    public function flush(): void
    {
        $this->store()->forever(self::PREFIX.'generation', (string) Str::ulid());
    }

    /** Event listener entry point. */
    public function handle(object $event): void
    {
        $this->flush();
    }

    /**
     * The moment an entry for this token stops being usable: the earlier of
     * Road's `expiresAt` and the token's own `exp`. Reading `exp` unverified is
     * safe here because it can only shorten a lifetime, never extend one, and a
     * forged token only shortens its own entry. Null means neither is known, so
     * the TTL alone governs.
     */
    public static function usableUntil(BridgeContext $context, string $token): ?float
    {
        $bounds = array_filter(
            [$context->expiresAt, self::tokenExp($token)],
            static fn (?int $v): bool => $v !== null,
        );

        return $bounds === [] ? null : (float) min($bounds);
    }

    private static function tokenExp(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        return is_array($claims) && is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
    }

    private function contextKey(string $key, string $generation): string
    {
        return self::PREFIX.'ctx:'.$generation.':'.$key;
    }

    private function seconds(string $option, int $default): int
    {
        return max(0, (int) $this->config->get('road.platform_bridge.'.$option, $default));
    }

    private function store(): CacheRepository
    {
        $name = $this->config->get('road.platform_bridge.cache_store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }
}
