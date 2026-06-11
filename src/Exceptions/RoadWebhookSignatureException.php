<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use B1Road\Laravel\Webhooks\WebhookSignatureVerifier;
use Throwable;

/**
 * A webhook delivery failed signature verification. Thrown by integrators who
 * call {@see WebhookSignatureVerifier} directly; the
 * bundled `road.webhook` middleware returns a 401 JSON response instead.
 */
final class RoadWebhookSignatureException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Webhook signature verification failed.',
        string $errorCode = 'webhook_signature_invalid',
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
