<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

final readonly class TokenSet
{
    /**
     * @param  array<string,mixed>  $userPayload  Verified ID-token claims.
     * @param  string|null  $roadUserId  The person's Road user id (a UUID), kept
     *                                   once `Road::roadUserId()` has read it. Not
     *                                   `userPayload['sub']`, the Auth Server id.
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?string $idToken,
        public int $expiresAt,
        public array $userPayload,
        public ?string $roadUserId = null,
    ) {}

    public function isExpired(int $now = 0): bool
    {
        return $this->expiresAt <= ($now ?: time());
    }

    public function withRoadUserId(string $roadUserId): self
    {
        return new self(
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            idToken: $this->idToken,
            expiresAt: $this->expiresAt,
            userPayload: $this->userPayload,
            roadUserId: $roadUserId,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'idToken' => $this->idToken,
            'expiresAt' => $this->expiresAt,
            'userPayload' => $this->userPayload,
            'roadUserId' => $this->roadUserId,
        ];
    }

    /** @param  array<string,mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: (string) $data['accessToken'],
            refreshToken: isset($data['refreshToken']) ? (string) $data['refreshToken'] : null,
            idToken: isset($data['idToken']) ? (string) $data['idToken'] : null,
            expiresAt: (int) $data['expiresAt'],
            userPayload: (array) ($data['userPayload'] ?? []),
            roadUserId: isset($data['roadUserId']) ? (string) $data['roadUserId'] : null,
        );
    }
}
