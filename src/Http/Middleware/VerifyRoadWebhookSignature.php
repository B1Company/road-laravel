<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Webhooks\WebhookSignatureVerifier;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Road webhook delivery's signature before the controller runs.
 * Fail-closed: returns 503 when no secret is configured (so a misconfigured
 * endpoint is obvious on the first delivery) and 401 on a bad signature. The
 * body is read raw — never re-encoded — so the HMAC matches the exact bytes
 * Road signed. JSON-only responses, since this is a server-to-server route.
 */
final class VerifyRoadWebhookSignature
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Local-dev escape hatch — honored only outside production.
        if (! (bool) $this->config->get('road.webhooks.verify', true) && ! $this->app->environment('production')) {
            return $next($request);
        }

        $secret = (string) $this->config->get('road.webhooks.secret', '');
        if ($secret === '') {
            return response()->json([
                'error' => [
                    'code' => 'webhook_not_configured',
                    'message' => 'Webhook endpoint not configured; set ROAD_WEBHOOK_SECRET or road.webhooks.secret.',
                ],
            ], 503);
        }

        $verifier = new WebhookSignatureVerifier($secret, (int) $this->config->get('road.webhooks.tolerance', 300));

        $ok = $verifier->verify(
            $request->getContent(),
            $request->header('X-Road-Signature'),
            $request->header('X-Road-Timestamp'),
        );

        if (! $ok) {
            return response()->json([
                'error' => [
                    'code' => 'webhook_signature_invalid',
                    'message' => 'Webhook signature verification failed.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
