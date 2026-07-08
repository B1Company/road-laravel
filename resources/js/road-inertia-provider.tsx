/**
 * Road Inertia bridge — wires `@b1-road/react` (cookie mode) to the `road`
 * shared props the Laravel-side `ShareRoadContext` middleware injects, so the
 * Auth Server JWT stays in the Laravel process and the browser only ever holds
 * the session cookie and talks to the `/road-api/*` BFF proxy.
 *
 *   // resources/js/app.tsx
 *   createInertiaApp({
 *     setup({ App, props }) {
 *       return (
 *         <RoadInertiaProvider>
 *           <App {...props} />
 *         </RoadInertiaProvider>
 *       );
 *     },
 *   });
 *
 * This file is the single source of truth for the bridge. The Laravel SDK ships
 * a byte-identical copy at `resources/js/road-inertia-provider.tsx` for
 * `vendor:publish` (a drift-guard test keeps the two in lockstep).
 */

import { RoadProvider } from "@b1-road/react";
import { router, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";

/**
 * The `road` shared-prop shape injected by `ShareRoadContext`. No JWT,
 * refresh token, or permission set — those never leave the Laravel process.
 */
export type RoadInertiaProps = {
  apiBaseUrl: string;
  user: { id: string; name: string; email: string; avatarUrl?: string } | null;
  currentBusinessUnitId: string | null;
  loginUrl: string;
  logoutUrl: string;
};

type PageProps = { road: RoadInertiaProps };

export type RoadInertiaProviderProps = {
  children: ReactNode;
  /**
   * Where to POST the active business-unit id when the user switches BUs.
   * Defaults to the route the Laravel installer mounts.
   */
  selectBusinessUnitUrl?: string;
};

/** Read Laravel's CSRF token from the XSRF-TOKEN cookie for the BU-select POST. */
function readXsrfToken(): string {
  if (typeof document === "undefined") return "";
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : "";
}

/**
 * Wraps the React tree in `<RoadProvider>` using the `road` shared props.
 * On a server-side session expiry the SDK calls `onUnauthenticated`, which
 * restarts the OIDC flow preserving the intended URL.
 */
export function RoadInertiaProvider({
  children,
  selectBusinessUnitUrl = "/road/business-unit",
}: RoadInertiaProviderProps) {
  const { road } = usePage<PageProps>().props;

  return (
    <RoadProvider
      apiBaseUrl={road.apiBaseUrl}
      businessUnitId={road.currentBusinessUnitId ?? undefined}
      onUnauthenticated={() => {
        const intended = encodeURIComponent(
          window.location.pathname + window.location.search,
        );
        window.location.assign(`${road.loginUrl}?intended=${intended}`);
      }}
      onSelectBusinessUnit={(id) => {
        // The Laravel SDK's business-unit route is a JSON API (it returns
        // { currentBusinessUnitId }), not an Inertia endpoint — POST it with
        // fetch, then reload to re-sync `props.road.currentBusinessUnitId`.
        // Issuing an Inertia visit here would reject the JSON response
        // ("all Inertia requests must receive a valid Inertia response").
        void fetch(selectBusinessUnitUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-XSRF-TOKEN": readXsrfToken(),
          },
          credentials: "same-origin",
          body: JSON.stringify({ id }),
        }).then(() => router.reload());
      }}
    >
      {children}
    </RoadProvider>
  );
}

/** Escape hatch — read the `road` shared props directly. */
export function useRoadInertia(): RoadInertiaProps {
  return usePage<PageProps>().props.road;
}
