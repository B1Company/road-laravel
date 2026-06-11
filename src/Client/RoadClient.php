<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Client\Resources\BusinessUnits;
use B1Road\Laravel\Client\Resources\BusinessUnitScope;
use B1Road\Laravel\Client\Resources\Iam;
use B1Road\Laravel\Client\Resources\Invitations;
use B1Road\Laravel\Client\Resources\Me;

/**
 * Root client for the Road API. Mirrors
 * `apps/sdks/road-nestjs/src/client/road-client.ts` at the surface level:
 * `me()`, `businessUnits()`, `iam()`, and `invitations()`.
 */
class RoadClient
{
    private ?Me $me = null;

    private ?BusinessUnits $businessUnits = null;

    private ?Iam $iam = null;

    private ?Invitations $invitations = null;

    public function __construct(private readonly HttpTransportInterface $http) {}

    public function me(): Me
    {
        return $this->me ??= new Me($this->http);
    }

    /**
     * `businessUnits()` returns the resource (`->get()`, `->create()`, …);
     * `businessUnits($buId)` is the shorthand to a navigator scope
     * (`->members()`, `->roles()`, `->fetch()`) — mirroring the callable
     * `road.businessUnits` / `road.businessUnits(id)` in @b1-road/nestjs.
     */
    public function businessUnits(?string $buId = null): BusinessUnits|BusinessUnitScope
    {
        $resource = $this->businessUnits ??= new BusinessUnits($this->http);

        return $buId !== null ? $resource($buId) : $resource;
    }

    public function iam(): Iam
    {
        return $this->iam ??= new Iam($this->http);
    }

    public function invitations(): Invitations
    {
        return $this->invitations ??= new Invitations($this->http);
    }
}
