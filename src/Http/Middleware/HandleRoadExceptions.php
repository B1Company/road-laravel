<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use Closure;
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
    public function __construct(private readonly RoadContext $context) {}

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
            ? $this->jsonResponse($e)
            : $this->htmlRedirect($request, $e);
    }

    private function jsonResponse(RoadException $e): JsonResponse
    {
        $body = $e->toErrorBody();
        if (! isset($body['requestId'])) {
            $body['requestId'] = $this->context->requestId();
        }

        return new JsonResponse(['error' => $body], $e->httpStatus());
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
