/**
 * Road Inertia bridge — published into the consumer project via
 * `php artisan vendor:publish --tag=road-inertia`. Once published, the
 * file lives in the consumer's `resources/js/lib/` and is owned by
 * them (edit freely; package upgrades won't overwrite without explicit
 * `--force`).
 *
 * Depends on:
 *   - @b1-road/react >= 0.4.0 (cookie mode)
 *   - @inertiajs/react (Inertia v1 or v2 — both supported)
 */

import { RoadProvider } from '@b1-road/react';
import { router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type RoadInertiaProps = {
  apiBaseUrl: string;
  user: { id: string; name: string; email: string; avatarUrl?: string } | null;
  currentBusinessUnitId: string | null;
  loginUrl: string;
  logoutUrl: string;
};

type PageProps = { road: RoadInertiaProps };

/**
 * Wraps the React tree in a <RoadProvider> using shared props injected
 * by the Laravel-side ShareRoadContext middleware. No JWT lives in the
 * browser — fetches go through the `/road-api/*` BFF proxy with the
 * Laravel session cookie.
 */
export function RoadInertiaProvider({ children }: { children: ReactNode }) {
  const { road } = usePage<PageProps>().props;

  return (
    <RoadProvider
      apiBaseUrl={road.apiBaseUrl}
      businessUnitId={road.currentBusinessUnitId ?? undefined}
      onUnauthenticated={() => {
        // Session expired or refresh failed server-side. Send the user
        // back through the OIDC flow, preserving the intended URL.
        const intended = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.assign(`${road.loginUrl}?intended=${intended}`);
      }}
      onSelectBusinessUnit={(id) =>
        router.post('/road/business-unit', { id }, { preserveScroll: true })
      }
    >
      {children}
    </RoadProvider>
  );
}

/** Escape hatch — read the shared Road props directly. */
export function useRoadInertia(): RoadInertiaProps {
  return usePage<PageProps>().props.road;
}
