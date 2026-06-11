<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\BusinessUnitController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A request with a started session bound, as the web group would provide. */
function requestWithSession(string $method, array $body = []): Request
{
    $request = Request::create('/road/business-unit', $method, $body);
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

it('persists the selected business unit in the session', function () {
    $request = requestWithSession('POST', ['id' => 'bu_1']);

    $response = (new BusinessUnitController)->set($request);

    expect($response->getData(true))->toBe(['currentBusinessUnitId' => 'bu_1']);
    expect($request->session()->get('road.current_business_unit_id'))->toBe('bu_1');
});

it('clears the selected business unit', function () {
    $request = requestWithSession('DELETE');
    $request->session()->put('road.current_business_unit_id', 'bu_1');

    $response = (new BusinessUnitController)->clear($request);

    expect($response->getData(true))->toBe(['currentBusinessUnitId' => null]);
    expect($request->session()->get('road.current_business_unit_id'))->toBeNull();
});

it('validates that an id is required', function () {
    expect(fn () => (new BusinessUnitController)->set(requestWithSession('POST', [])))
        ->toThrow(ValidationException::class);
});
