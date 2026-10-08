<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Invitation;

/**
 * `Road::client()->invitations()->accept($id, $token)` — the per-invitation ops that
 * don't need the owning BU in hand (the API resolves it server-side). Listing
 * and create live on the BU-scoped {@see InvitationCollection}.
 *
 * Parity note: `@b1-road/nestjs`'s `accept()` returns `{ invitation, member }`,
 * but the live API answers `accept` with the invitation only — so this returns
 * an {@see Invitation}. Fetch the new membership via
 * `businessUnits($buId)->members()` when you need it.
 */
final class Invitations
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /**
     * Accept with the single-use token from the invitation link (the `?token=`
     * the invitation email opens with). The token is the proof of the
     * invitation; the signed-in email is not compared.
     */
    public function accept(string $invitationId, string $token): Invitation
    {
        $body = $this->http->request('POST', '/organization/invitations/'.rawurlencode($invitationId).'/accept', ['token' => $token]);

        return Invitation::from($this->unwrap($body));
    }

    public function reject(string $invitationId): Invitation
    {
        $body = $this->http->request('POST', '/organization/invitations/'.rawurlencode($invitationId).'/reject');

        return Invitation::from($this->unwrap($body));
    }
}
