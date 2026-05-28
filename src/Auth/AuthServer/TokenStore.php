<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

interface TokenStore
{
    public function get(): ?TokenSet;

    public function put(TokenSet $tokens): void;

    public function clear(): void;
}
