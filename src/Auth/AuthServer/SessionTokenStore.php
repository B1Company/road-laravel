<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

use Illuminate\Contracts\Session\Session;

final class SessionTokenStore implements TokenStore
{
    private const SESSION_KEY = 'road.tokens';

    public function __construct(private readonly Session $session)
    {
    }

    public function get(): ?TokenSet
    {
        $raw = $this->session->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return null;
        }

        return TokenSet::fromArray($raw);
    }

    public function put(TokenSet $tokens): void
    {
        $this->session->put(self::SESSION_KEY, $tokens->toArray());
        $this->session->save();
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->session->save();
    }
}
