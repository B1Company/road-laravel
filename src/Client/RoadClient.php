<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Client\Resources\BusinessUnits;
use B1Road\Laravel\Client\Resources\Me;
use BadMethodCallException;

/**
 * Root client for the Road API. Mirrors
 * `apps/sdks/road-nestjs/src/client/road-client.ts` 1:1.
 *
 * MVP exposes `me()` and `businessUnits()`. Resource accessors reserved
 * for follow-up releases (`members`, `roles`, `invitations`, `iam`)
 * throw BadMethodCallException so the public shape never moves between
 * milestones.
 */
class RoadClient
{
    private ?Me $me = null;

    private ?BusinessUnits $businessUnits = null;

    public function __construct(private readonly HttpTransport $http)
    {
    }

    public function me(): Me
    {
        return $this->me ??= new Me($this->http);
    }

    public function businessUnits(): BusinessUnits
    {
        return $this->businessUnits ??= new BusinessUnits($this->http);
    }

    public function members(): never
    {
        throw new BadMethodCallException(
            'RoadClient::members() is reserved for a follow-up release (full client surface).'
        );
    }

    public function roles(): never
    {
        throw new BadMethodCallException(
            'RoadClient::roles() is reserved for a follow-up release (full client surface).'
        );
    }

    public function invitations(): never
    {
        throw new BadMethodCallException(
            'RoadClient::invitations() is reserved for a follow-up release (full client surface).'
        );
    }

    public function iam(): never
    {
        throw new BadMethodCallException(
            'RoadClient::iam() is reserved for a follow-up release (IAM control plane).'
        );
    }
}
