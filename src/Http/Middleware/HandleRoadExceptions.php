<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadException;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders thrown RoadException subclasses into the right response
 * shape for the caller:
 *
 *   - API caller (Accept: application/json, XHR, /road-api/*):
 *     `{ error: { code, message, requestId, docs? } }` with the
 *     exception's httpStatus().
 *   - Browser caller (Accept: text/html):
 *     for 401-class errors, redirect back to the login URL with
 *     `?intended=<current-url>&error=<code>`; for other errors,
 *     redirect back to "/" with a flash message under
 *     `errors.road`.
 *
 * **Wiring.** The real mechanism is an exception-handler `renderable`
 * callback registered in RoadServiceProvider::boot() that delegates to
 * {@see render()}. This is deliberate: Laravel's routing pipeline wraps
 * each pipe and the route destination in its own try/catch and renders
 * any thrown exception via the exception handler *before* it can bubble
 * back up to an earlier middleware's try/catch — so a route/group
 * middleware can never catch a downstream controller exception. The
 * exception handler is the only layer that sees them.
 *
 * The class is still registered as the `road.errors` middleware alias
 * for API-surface stability and to catch the rare exception thrown by a
 * *sibling* middleware earlier in the same group; both paths funnel
 * through {@see render()} so behavior is identical either way. Non-Road
 * exceptions pass through unchanged.
 */
final class HandleRoadExceptions
{
    public function __construct(
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        } catch (RoadException $e) {
            return $this->render($request, $e);
        }
    }

    /**
     * Map a RoadException to its caller-appropriate response. Invoked by
     * both the middleware and the exception-handler renderable.
     */
    public function render(Request $request, RoadException $e): Response
    {
        return $this->wantsJson($request)
            ? $this->jsonResponse($request, $e)
            : $this->htmlRedirect($request, $e);
    }

    private function jsonResponse(Request $request, RoadException $e): JsonResponse
    {
        $body = $e->toErrorBody();
        if (! isset($body['requestId'])) {
            $body['requestId'] = $this->context->requestId();
        }

        // Attach the authorization decision trace to a 403 ONLY when the caller
        // asked for it (`X-Road-Debug: 1` or `?debug=road`) AND the debug header
        // is enabled (auto-on outside production, off in prod, config kill
        // switch). A production 403 must never leak the caller's grants. Same
        // trigger + shape as @b1-road/nestjs's RoadDebugFilter.
        if ($e instanceof RoadAuthzException && $this->debugRequested($request) && $this->debugEnabled()) {
            $decision = $e->decision();
            if ($decision !== null) {
                $body['decision'] = $decision;
            }
        }

        return new JsonResponse(['error' => $body], $e->httpStatus());
    }

    private function debugRequested(Request $request): bool
    {
        return $request->header('X-Road-Debug') === '1'
            || $request->query('debug') === 'road';
    }

    private function debugEnabled(): bool
    {
        return (bool) $this->config->get('road.debug.header_enabled', false);
    }

    private function htmlRedirect(Request $request, RoadException $e): Response
    {
        if ($e->httpStatus() === 401) {
            $loginUrl = url('/auth/road/login').'?'.http_build_query([
                'intended' => $request->fullUrl(),
                'error' => $e->errorCode(),
            ]);

            return new RedirectResponse($loginUrl);
        }

        $request->session()->flash('errors.road', [
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'requestId' => $this->context->requestId(),
        ]);

        return new RedirectResponse('/');
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax()
            || $request->is('road-api/*');
    }
}
