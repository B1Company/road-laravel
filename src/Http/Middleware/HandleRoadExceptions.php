<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Converts thrown RoadException subclasses into JSON `{ error: {...} }`
 * responses with the same shape NestJS + React emit. Non-Road exceptions
 * pass through unchanged.
 */
final class HandleRoadExceptions
{
    public function __construct(private readonly RoadContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        } catch (RoadException $e) {
            return $this->toJson($e);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function toJson(RoadException $e): JsonResponse
    {
        $body = $e->toErrorBody();
        if (! isset($body['requestId'])) {
            $body['requestId'] = $this->context->requestId();
        }

        return new JsonResponse(['error' => $body], $e->httpStatus());
    }
}
