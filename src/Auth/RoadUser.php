<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class RoadUser implements Authenticatable
{
    /**
     * @param  array<string,mixed>  $payload  Raw verified ID-token claims.
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public ?string $avatarUrl = null,
        public array $payload = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'avatarUrl' => $this->avatarUrl,
        ];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
