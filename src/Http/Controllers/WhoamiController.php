<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use B1Road\Laravel\Facades\Road;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class WhoamiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $user = Road::user();

        if ($user === null) {
            return new JsonResponse(['user' => null], 200);
        }

        return new JsonResponse(['user' => $user->toArray()], 200);
    }
}
