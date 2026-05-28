<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Client\Resources\BusinessUnits;
use B1Road\Laravel\Client\Resources\Me;

/**
 * Root client for the Road API. Mirrors
 * `apps/sdks/road-nestjs/src/client/road-client.ts` at the surface level.
 *
 * Ships `me()` and `businessUnits()`. Additional resources (members,
 * roles, invitations, IAM control plane) land in follow-up releases —
 * intentionally not stubbed on the public surface so autocomplete never
 * offers a method that doesn't work.
 */
class RoadClient
{
    private ?Me $me = null;

    private ?BusinessUnits $businessUnits = null;

    public function __construct(private readonly HttpTransportInterface $http)
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
}
