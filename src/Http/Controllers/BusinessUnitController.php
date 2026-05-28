<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Persists the user's selected Business Unit in the session so
 * `ShareRoadContext` can echo it into Inertia shared props on every
 * subsequent request.
 *
 * Wired to `POST /road/business-unit` by routes/auth.php. The
 * publishable Inertia provider POSTs here from
 * `onSelectBusinessUnit`.
 */
final class BusinessUnitController extends Controller
{
    public function set(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128'],
        ]);

        $request->session()->put('road.current_business_unit_id', $validated['id']);

        return new JsonResponse(['currentBusinessUnitId' => $validated['id']], 200);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->session()->forget('road.current_business_unit_id');

        return new JsonResponse(['currentBusinessUnitId' => null], 200);
    }
}
