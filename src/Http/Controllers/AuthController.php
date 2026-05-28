<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuthController extends Controller
{
    public function __construct(private readonly OidcProvider $oidc)
    {
    }

    public function login(Request $request): RedirectResponse
    {
        return $this->oidc->redirectToLogin($request);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->oidc->handleCallback($request);

        $intended = $request->session()->pull('road.intended_url');
        $target = is_string($intended) && $intended !== '' ? $intended : '/';

        return new RedirectResponse($target);
    }

    public function logout(Request $request): RedirectResponse
    {
        return $this->oidc->logout($request);
    }
}
