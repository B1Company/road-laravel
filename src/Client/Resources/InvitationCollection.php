<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Invitation;

/**
 * Invitations owned by a business unit — listing + create + cancel. The
 * per-id accept/reject ops (which don't need the BU context) live on
 * {@see Invitations}. Mirrors `InvitationCollection` in road-nestjs.
 *
 * @extends Paginates<Invitation>
 */
final class InvitationCollection extends Paginates
{
    use UnwrapsData;

    public function __construct(HttpTransportInterface $http, private readonly string $buId)
    {
        parent::__construct($http);
    }

    /** @param  array<string,mixed>  $input */
    public function create(array $input): Invitation
    {
        $body = $this->http->request('POST', $this->base(), $input);

        return Invitation::from($this->unwrap($body));
    }

    /**
     * Cancel is a state transition (`POST .../cancel`), not a delete. The API
     * returns the updated invitation, so we hand it back (NestJS returns void;
     * the richer return is parity-safe).
     */
    public function cancel(string $invitationId): Invitation
    {
        $body = $this->http->request('POST', $this->base().'/'.rawurlencode($invitationId).'/cancel');

        return Invitation::from($this->unwrap($body));
    }

    protected function listPath(): string
    {
        return $this->base();
    }

    protected function mapRow(array $row): Invitation
    {
        return Invitation::from($row);
    }

    private function base(): string
    {
        return '/organization/business-units/'.rawurlencode($this->buId).'/invitations';
    }
}
