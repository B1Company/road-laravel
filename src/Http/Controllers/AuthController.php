<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuthController extends Controller
{
    public function __construct(private readonly OidcProvider $oidc) {}

    public function login(Request $request): RedirectResponse
    {
        return $this->oidc->redirectToLogin($request);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->oidc->handleCallback($request);

        $intended = $request->session()->pull('road.intended_url');

        return new RedirectResponse($this->safeRedirectTarget($intended));
    }

    /**
     * Open-redirect guard (security review SDK-F1 / B1-303).
     *
     * `intended` is attacker-settable on the unauthenticated `GET
     * /auth/road/login` and is stored verbatim in the session, then emitted
     * here in the `Location` header as-is by Symfony's `RedirectResponse`
     * (its `htmlspecialchars` escaping touches only the meta-refresh body,
     * never the header). Without a check, a link like
     * `…/auth/road/login?intended=https://evil.example/phish` lands the victim
     * on an attacker page immediately after a genuine login (CWE-601).
     *
     * Honor only a same-origin absolute PATH: require a single leading `/` and
     * reject a second `/` (scheme-relative `//evil`) or `\` (`/\evil`, which
     * some browsers normalize to `//evil`). Anything else falls back to the app
     * root. This is the sink — the one point where the value becomes a redirect
     * — so it holds no matter how `road.intended_url` was set.
     *
     * Control characters are rejected outright BEFORE the structural check
     * (B1-329). Browsers strip tab, CR and LF from a URL before resolving it,
     * so `/\t/evil.example` reaches the network as `//evil.example` — but the
     * byte at index 1 is the tab, not the second slash, and a check that reads
     * fixed positions never sees it. Rejecting rather than stripping is the
     * safer half of that choice: a legitimate in-app path never contains a
     * control byte, so there is nothing to salvage by normalizing.
     */
    private function safeRedirectTarget(mixed $intended): string
    {
        if (! is_string($intended) || $intended === '' || $intended[0] !== '/') {
            return '/';
        }

        // Any C0 control byte, DEL, or whitespace the structural check below
        // would index past. `\v` and `\f` are included because PHP's `\s` does
        // not cover every byte a browser may discard.
        if (preg_match('/[\x00-\x20\x7F]/', $intended) === 1) {
            return '/';
        }

        if (isset($intended[1]) && ($intended[1] === '/' || $intended[1] === '\\')) {
            return '/';
        }

        return $intended;
    }

    public function logout(Request $request): RedirectResponse
    {
        return $this->oidc->logout($request);
    }
}
