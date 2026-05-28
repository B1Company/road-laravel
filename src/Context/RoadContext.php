<?php

declare(strict_types=1);

namespace B1Road\Laravel\Context;

use B1Road\Laravel\Auth\RoadUser;
use Symfony\Component\Uid\Ulid;

final class RoadContext
{
    private ?RoadUser $user = null;

    private ?string $token = null;

    private ?string $requestId = null;

    public function user(): ?RoadUser
    {
        return $this->user;
    }

    public function setUser(?RoadUser $user): void
    {
        $this->user = $user;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    public function requestId(): string
    {
        return $this->requestId ??= (string) new Ulid;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }
}
