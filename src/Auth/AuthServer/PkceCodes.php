<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

final readonly class PkceCodes
{
    public function __construct(
        public string $verifier,
        public string $challenge,
        public string $state,
        public string $nonce,
    ) {}
}
