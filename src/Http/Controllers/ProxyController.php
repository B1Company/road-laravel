<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Http\ProxyPathFilter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * BFF proxy: forwards `/road-api/{path}` calls to the configured
 * `road.api.base_url`, attaching the user's session-stored Bearer
 * server-side. The browser never sees the JWT.
 *
 * Allowlist:   `road.proxy.allow` (default: organization/*, iam/identity/*,
 *              iam/authorization/*). Paths outside the allowlist return 404
 *              before any upstream call.
 *
 * Header rules:
 *   - Request:  forwards method, body, content-type, X-Request-Id; injects
 *               `Authorization: Bearer <session token>`.
 *   - Response: passes through status + body unchanged; strips Set-Cookie /
 *               Authorization / Host / Transfer-Encoding from the
 *               returned headers (those are connection-level or unsafe to
 *               replay to the browser).
 */
final class ProxyController extends Controller
{
    /** Headers we never forward upstream or pass back to the browser. */
    private const HOP_BY_HOP_HEADERS = [
        'host',
        'content-length',
        'authorization',
        'cookie',
        'set-cookie',
        'connection',
        'keep-alive',
        'transfer-encoding',
        'upgrade',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(Request $request, string $path = ''): Response
    {
        if (! $this->isPathAllowed($path)) {
            return new Response(
                json_encode(['error' => ['code' => 'not_found', 'message' => 'Path not in proxy allowlist.']]) ?: '',
                404,
                ['Content-Type' => 'application/json'],
            );
        }

        $token = $this->context->token();
        if ($token === null || $token === '') {
            // The road middleware should have populated context. If it didn't,
            // surface 401 (defense-in-depth — should be unreachable in prod).
            return new Response(
                json_encode(['error' => ['code' => 'unauthenticated']]) ?: '',
                401,
                ['Content-Type' => 'application/json'],
            );
        }

        $upstreamUrl = $this->buildUpstreamUrl($path, $request);

        $pending = $this->buildPendingRequest($token, $request);

        try {
            $response = $this->sendUpstream($pending, $request->method(), $upstreamUrl, $request);
        } catch (Throwable $e) {
            return new Response(
                json_encode(['error' => ['code' => 'network_error', 'message' => $e->getMessage()]]) ?: '',
                502,
                ['Content-Type' => 'application/json'],
            );
        }

        return $this->buildBrowserResponse($response);
    }

    private function isPathAllowed(string $path): bool
    {
        /** @var list<string> $allow */
        $allow = array_values(array_map('strval', (array) $this->config->get('road.proxy.allow', [])));

        return (new ProxyPathFilter($allow))->isAllowed($path);
    }

    private function buildUpstreamUrl(string $path, Request $request): string
    {
        $base = rtrim((string) $this->config->get('road.api.base_url', ''), '/');
        // The allowlist is version-less (`organization/*`, `iam/*`) and the
        // browser SDKs send version-less paths, so compose the API's
        // `/api/{version}` prefix here — the same prefix the server-side
        // HttpTransport adds. Without it the upstream call 404s.
        $version = trim((string) $this->config->get('road.api.version', 'alpha'), '/');
        $url = $base.'/api/'.$version.'/'.ltrim($path, '/');

        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $url .= '?'.$query;
        }

        return $url;
    }

    private function buildPendingRequest(string $token, Request $request): PendingRequest
    {
        $pending = $this->http
            ->withToken($token)
            ->withHeaders($this->forwardedHeaders($request))
            ->timeout((int) $this->config->get('road.api.timeout', 10))
            ->acceptJson();

        return $pending;
    }

    /** @return array<string,string> */
    private function forwardedHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }
            $headers[$name] = is_array($values) ? implode(', ', array_filter($values, 'is_string')) : (string) $values;
        }

        // Always thread our request id, even if the browser didn't send one.
        $headers['X-Request-Id'] = $this->context->requestId();

        return $headers;
    }

    private function sendUpstream(
        PendingRequest $pending,
        string $method,
        string $url,
        Request $request,
    ): HttpResponse {
        $body = $request->getContent();
        $method = strtoupper($method);

        if ($method === 'GET' || $method === 'HEAD' || $method === 'DELETE') {
            return $pending->send($method, $url);
        }

        return $pending
            ->withBody($body !== '' ? $body : '', (string) $request->header('Content-Type', 'application/json'))
            ->send($method, $url);
    }

    private function buildBrowserResponse(HttpResponse $upstream): Response
    {
        $headers = [];
        foreach ($upstream->headers() as $name => $values) {
            if (in_array(strtolower($name), self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }
            $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        if (! isset($headers['Content-Type']) && ! isset($headers['content-type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        return new Response($upstream->body(), $upstream->status(), $headers);
    }
}
