<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks;

use B1Road\Laravel\Webhooks\Events\InvitationAccepted;
use B1Road\Laravel\Webhooks\Events\InvitationCancelled;
use B1Road\Laravel\Webhooks\Events\InvitationCreated;
use B1Road\Laravel\Webhooks\Events\InvitationRejected;
use B1Road\Laravel\Webhooks\Events\MemberJoined;
use B1Road\Laravel\Webhooks\Events\MemberReinstated;
use B1Road\Laravel\Webhooks\Events\MemberRemoved;
use B1Road\Laravel\Webhooks\Events\MemberRoleChanged;
use B1Road\Laravel\Webhooks\Events\MemberSuspended;
use B1Road\Laravel\Webhooks\Payloads\InvitationWebhookData;
use B1Road\Laravel\Webhooks\Payloads\MemberRoleChangedWebhookData;
use B1Road\Laravel\Webhooks\Payloads\MemberWebhookData;
use Spatie\LaravelData\Data;

/**
 * Single source mapping each Road webhook event string to its typed Laravel
 * event class and payload DTO. Mirrors `ROAD_WEBHOOK_EVENT_TYPES` /
 * `RoadWebhookPayloads` in `@b1-road/types`. An event not in this map is
 * forward-compatible: the controller still fires {@see Events\RoadWebhookReceived}
 * and returns 200.
 */
final class RoadEventMap
{
    /** @var array<string, array{0: class-string, 1: class-string<Data>}> */
    private const MAP = [
        'organization.invitation.created' => [InvitationCreated::class, InvitationWebhookData::class],
        'organization.invitation.accepted' => [InvitationAccepted::class, InvitationWebhookData::class],
        'organization.invitation.rejected' => [InvitationRejected::class, InvitationWebhookData::class],
        'organization.invitation.cancelled' => [InvitationCancelled::class, InvitationWebhookData::class],
        'organization.member.joined' => [MemberJoined::class, MemberWebhookData::class],
        'organization.member.suspended' => [MemberSuspended::class, MemberWebhookData::class],
        'organization.member.reinstated' => [MemberReinstated::class, MemberWebhookData::class],
        'organization.member.removed' => [MemberRemoved::class, MemberWebhookData::class],
        'organization.member.role-changed' => [MemberRoleChanged::class, MemberRoleChangedWebhookData::class],
    ];

    /**
     * Resolve the typed event + payload classes for an event string.
     *
     * @return array{0: class-string, 1: class-string<Data>}|null
     */
    public static function for(string $event): ?array
    {
        return self::MAP[$event] ?? null;
    }

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return array_keys(self::MAP);
    }
}
