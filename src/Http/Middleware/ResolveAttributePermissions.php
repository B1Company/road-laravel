<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Attributes\RequirePermission as RequirePermissionAttr;
use B1Road\Laravel\Attributes\SkipAuthorization;
use B1Road\Laravel\Facades\Road;
use Closure;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reflects the route's controller action for `#[RequirePermission(...)]`
 * (method or class level), then enforces the same way the
 * `road.permission` string-form middleware does. One enforcement code
 * path (SDK_DX_BAR §1).
 *
 * Closure routes are intentionally not supported — there's no method to
 * reflect. Use the string-form middleware on closures instead.
 *
 * `#[SkipAuthorization]` on the method opts out (overrides a
 * class-level attribute for that one action).
 */
final class ResolveAttributePermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $reflected = $this->reflectAction($request);
        if ($reflected !== null) {
            [$class, $method] = $reflected;

            if ($this->methodSkips($method)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }

            $attribute = $this->methodAttribute($method) ?? $this->classAttribute($class);
            if ($attribute !== null) {
                $scopeId = $this->resolveScopeId($request, $attribute->in);
                Road::assert(Road::can($attribute->action, $attribute->subject)->in($scopeId));
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /** @return array{0: ReflectionClass<object>, 1: ReflectionMethod}|null */
    private function reflectAction(Request $request): ?array
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $action = $route->getAction();
        $uses = $action['uses'] ?? null;

        // Closure routes have no class to reflect.
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return null;
        }

        [$controller, $method] = explode('@', $uses, 2);
        if (! class_exists($controller)) {
            return null;
        }

        try {
            $class = new ReflectionClass($controller);
            $methodRef = $class->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        return [$class, $methodRef];
    }

    private function methodSkips(ReflectionMethod $method): bool
    {
        return $method->getAttributes(SkipAuthorization::class) !== [];
    }

    private function methodAttribute(ReflectionMethod $method): ?RequirePermissionAttr
    {
        $attrs = $method->getAttributes(RequirePermissionAttr::class);
        if ($attrs === []) {
            return null;
        }

        /** @var RequirePermissionAttr $instance */
        $instance = $attrs[0]->newInstance();

        return $instance;
    }

    /** @param  ReflectionClass<object>  $class */
    private function classAttribute(ReflectionClass $class): ?RequirePermissionAttr
    {
        $attrs = $class->getAttributes(RequirePermissionAttr::class);
        if ($attrs === []) {
            return null;
        }

        /** @var RequirePermissionAttr $instance */
        $instance = $attrs[0]->newInstance();

        return $instance;
    }

    private function resolveScopeId(Request $request, string $source): string
    {
        if (str_starts_with($source, 'input:')) {
            $key = substr($source, 6);
            $value = $request->input($key);
            if (! is_string($value) || $value === '') {
                throw new \LogicException(
                    "#[RequirePermission]: request input '$key' is missing or empty (expected the scope id)."
                );
            }

            return $value;
        }

        $value = $request->route($source);
        if (! is_string($value) || $value === '') {
            throw new \LogicException(
                "#[RequirePermission]: route parameter '$source' is missing or empty (expected the scope id)."
            );
        }

        return $value;
    }
}
