<?php

declare(strict_types=1);

use B1Road\Laravel\Testing\InMemoryBackend;

/**
 * The fake's Road catalog is a copy, so a code the API adds or drops would leave
 * `Road::fake()` answering what the API no longer does. This reads the same
 * sources `roadPermissions()` reads, in `authz/seed/road-definitions.ts`.
 */
function apiModuleSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/api/src/modules/'.$path);
}

/** @return array<string, list<string>> Road's codes by the root that owns them. */
function roadDefinitionsFromApi(): array
{
    $owners = [
        'system' => 'system',
        'business_unit' => 'business_units',
        'developer_account' => 'developer_accounts',
        'platform_management' => 'platforms',
    ];
    $code = fn (string $action, string $subject): string => strtolower($action).':'.$subject;

    $catalog = [];
    foreach ([
        'iam/authorization/constants/permission-registry.ts',
        'organization/constants/permissions.ts',
        'developer/constants/permissions.ts',
    ] as $path) {
        preg_match_all(
            "/action: Actions\.(\w+),\s*subject: Subjects\.(\w+),.*?scopeType: '(\w+)'/s",
            apiModuleSource($path),
            $definitions,
            PREG_SET_ORDER,
        );
        foreach ($definitions as [, $action, $subject, $scopeType]) {
            $catalog[$owners[$scopeType]][] = $code($action, $subject);
        }
    }

    $seed = apiModuleSource('authz/seed/road-definitions.ts');
    preg_match('/const AUTHZ_ROUTE_CODES = \[(.*?)\] as const;/s', $seed, $routeCodes);
    preg_match_all('/action: Actions\.(\w+),\s*subject: Subjects\.(\w+)/', $routeCodes[1], $routes, PREG_SET_ORDER);
    preg_match('/const AUTHZ_ROUTE_DEFINITIONS.*?= \[(.*?)\]\.flatMap/s', $seed, $routeOwners);
    preg_match_all("/'(\w+)'/", $routeOwners[1], $scopeTypes);
    foreach ($scopeTypes[1] as $scopeType) {
        foreach ($routes as [, $action, $subject]) {
            $catalog[$owners[$scopeType]][] = $code($action, $subject);
        }
    }

    return array_map(function (array $codes): array {
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }, $catalog);
}

it('the fake declares exactly the codes Road declares, root by root', function () {
    $fake = (new ReflectionClass(InMemoryBackend::class))->getConstant('ROAD_CATALOG');
    foreach ($fake as $owner => $codes) {
        sort($codes);
        $fake[$owner] = $codes;
    }
    ksort($fake);
    $api = roadDefinitionsFromApi();
    ksort($api);

    expect($fake)->toBe($api);
});
