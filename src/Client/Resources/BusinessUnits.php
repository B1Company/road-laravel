<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\BusinessUnitDetail;
use B1Road\Laravel\DTO\BusinessUnitWithIncludes;

/**
 * `Road::client()->businessUnits()->...` — the BU entry resource. Mirrors
 * `BusinessUnitsResource` in road-nestjs: `get()` (with optional `include`),
 * `create()`, `update()`, and the `($buId)` shorthand to a {@see BusinessUnitScope}.
 */
final class BusinessUnits
{
    use UnwrapsData;

    /** Relations `get(include: …)` can eagerly expand. */
    private const INCLUDABLE = ['members', 'roles'];

    private static bool $fanOutWarned = false;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /**
     * Fetch a BU. With `include`, eagerly expands the named relations
     * (`members`, `roles`) and returns a {@see BusinessUnitWithIncludes};
     * without it, a plain {@see BusinessUnitDetail}.
     *
     * @param  list<string>  $include
     */
    public function get(string $buId, array $include = []): BusinessUnitDetail|BusinessUnitWithIncludes
    {
        $unknown = array_values(array_diff($include, self::INCLUDABLE));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown include key(s): %s. Allowed: %s.',
                implode(', ', $unknown),
                implode(', ', self::INCLUDABLE),
            ));
        }

        $detail = BusinessUnitDetail::from($this->unwrap(
            $this->http->request('GET', '/organization/business-units/'.rawurlencode($buId)),
        ));

        if ($include === []) {
            return $detail;
        }

        self::warnFanOutOnce();
        $scope = new BusinessUnitScope($this->http, $buId);

        return BusinessUnitWithIncludes::fromDetail(
            $detail,
            in_array('members', $include, true) ? $scope->members()->all() : null,
            in_array('roles', $include, true) ? $scope->roles()->all() : null,
        );
    }

    /**
     * Create a BU. The API requires a regex-validated, immutable `slug`
     * (`^[a-z0-9]+(?:-[a-z0-9]+)*$`); when omitted it's derived from `name`.
     *
     * @param  array<string,mixed>  $input
     */
    public function create(array $input): BusinessUnitDetail
    {
        $slug = $input['slug'] ?? null;
        if (! is_string($slug) || $slug === '') {
            $input['slug'] = self::deriveSlug((string) ($input['name'] ?? ''));
        }

        return BusinessUnitDetail::from($this->unwrap(
            $this->http->request('POST', '/organization/business-units', $input),
        ));
    }

    /** @param  array<string,mixed>  $input */
    public function update(string $buId, array $input): BusinessUnitDetail
    {
        return BusinessUnitDetail::from($this->unwrap(
            $this->http->request('PATCH', '/organization/business-units/'.rawurlencode($buId), $input),
        ));
    }

    /** Shorthand: `Road::client()->businessUnits($buId)` returns a scope. */
    public function __invoke(string $buId): BusinessUnitScope
    {
        return new BusinessUnitScope($this->http, $buId);
    }

    /**
     * Derive a URL-safe slug from a BU name to satisfy the API's
     * `^[a-z0-9]+(?:-[a-z0-9]+)*$` constraint. Lowercases, strips diacritics
     * when intl is available, collapses non-alphanumeric runs to a single
     * hyphen, trims; falls back to `business-unit` for an unslugable name.
     * Mirrors road-nestjs's `deriveSlug`.
     */
    private static function deriveSlug(string $name): string
    {
        $base = strtolower($name);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($base, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $base = (string) preg_replace('/\p{Mn}+/u', '', $normalized);
            }
        }
        $base = (string) preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');

        return $base !== '' ? $base : 'business-unit';
    }

    /**
     * `include` fans out client-side today (the API has no native expand yet),
     * so warn once per process that it costs extra round-trips — see
     * SDK_DX_BAR principle #5.
     */
    private static function warnFanOutOnce(): void
    {
        if (self::$fanOutWarned) {
            return;
        }
        self::$fanOutWarned = true;
        @trigger_error(
            'businessUnits()->get(include: …) fans out client-side for now; it collapses to a single round-trip once the Road API ships native include.',
            E_USER_DEPRECATED,
        );
    }
}
