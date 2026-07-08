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
 * Reach the full `<RoadProvider>` surface (theming, i18n, platform id, …) via
 * `providerProps` — the bridge keeps ownership of the props it derives from the
 * `road` shared props (base URL, current BU, the auth callbacks) and of the
 * cookie auth mode, so those cannot be overridden.
 *
 * This file is the single source of truth for the bridge. The Laravel SDK ships
 * a byte-identical copy at `resources/js/road-inertia-provider.tsx` for
 * `vendor:publish` (a drift-guard test keeps the two in lockstep).
 */

import { RoadProvider, type RoadProviderProps } from "@b1-road/react";
import { router, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";

/**
 * The `road` shared-prop shape injected by `ShareRoadContext`. No JWT,
 * refresh token, or permission set — those never leave the Laravel process.
 */
export type RoadInertiaProps = {
  apiBaseUrl: string;
  user: {
    id: string;
    name: string;
    email: string;
    avatarUrl?: string | null;
  } | null;
  currentBusinessUnitId: string | null;
  loginUrl: string;
  logoutUrl: string;
};

type PageProps = { road: RoadInertiaProps };

/**
 * The subset of `<RoadProvider>` props an integrator may pass through the
 * bridge. The bridge owns everything it derives from the `road` shared props
 * (`apiBaseUrl`, `businessUnitId`, `onUnauthenticated`, `onSelectBusinessUnit`)
 * and the cookie auth mode (`authMode`/`jwt`/`client`) — overriding those would
 * break the BFF "no JWT in the browser" invariant, so they are excluded here.
 * Everything else (`appearance`, `locale`, `localization`, `platformId`, CSRF
 * config, `withCredentials`, `retry`, `telemetry`, …) is yours to set.
 */
export type RoadProviderPassthrough = Omit<
  RoadProviderProps,
  | "children"
  | "apiBaseUrl"
  | "businessUnitId"
  | "onUnauthenticated"
  | "onSelectBusinessUnit"
  | "authMode"
  | "jwt"
  | "client"
>;

export type RoadInertiaProviderProps = {
  children: ReactNode;
  /**
   * Where to POST the active business-unit id when the user switches BUs.
   * Defaults to the route the Laravel installer mounts.
   */
  selectBusinessUnitUrl?: string;
  /**
   * Passed through to the underlying `<RoadProvider>` — theming (`appearance`),
   * i18n (`locale`/`localization`), `platformId`, CSRF config, etc. The
   * bridge-managed props (see {@link RoadProviderPassthrough}) cannot be set here.
   */
  providerProps?: RoadProviderPassthrough;
};

/** Read Laravel's CSRF token from the CSRF cookie for the BU-select POST. */
function readXsrfToken(cookieName: string): string {
  if (typeof document === "undefined") return "";
  const match = document.cookie.match(
    new RegExp(`(?:^|;\\s*)${cookieName}=([^;]+)`),
  );
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
  providerProps,
}: RoadInertiaProviderProps) {
  const page = usePage<PageProps>();
  const road = page.props.road;

  if (!road) {
    throw new Error(
      "[@b1-road/laravel-react] The `road` shared prop is missing. Ensure the " +
        "Road SDK's ShareRoadContext middleware is active (it auto-mounts when " +
        "inertiajs/inertia-laravel is installed; check `road.inertia.enabled` " +
        "is not false), then run `php artisan road:doctor` to verify wiring.",
    );
  }

  // Keep the bridge's CSRF cookie name consistent with the underlying client's
  // (default `XSRF-TOKEN`), so a passthrough override applies to both halves.
  const csrfCookieName = providerProps?.csrfCookieName ?? "XSRF-TOKEN";
  const csrfHeaderName = providerProps?.csrfHeaderName ?? "X-XSRF-TOKEN";

  // Defense-in-depth: `RoadProviderPassthrough` Omits the auth-mode keys, but
  // `Omit` is compile-time only — a JS caller (or a cast) could still smuggle
  // `authMode`/`jwt`/`client` in and flip the bridge out of cookie mode,
  // breaking the "no JWT in the browser" invariant. Strip them at runtime.
  const {
    authMode: _authMode,
    jwt: _jwt,
    client: _client,
    ...safeProviderProps
  } = (providerProps ?? {}) as Record<string, unknown>;

  return (
    <RoadProvider
      {...safeProviderProps}
      apiBaseUrl={road.apiBaseUrl}
      businessUnitId={road.currentBusinessUnitId ?? undefined}
      onUnauthenticated={() => {
        const intended = encodeURIComponent(
          window.location.pathname + window.location.search,
        );
        window.location.assign(`${road.loginUrl}?intended=${intended}`);
      }}
      onSelectBusinessUnit={(id) => {
        // Skip the round-trip when the selection already matches the server's
        // current BU — this suppresses the redundant POST + reload that the
        // provider's auto-select-first fires at mount on a fresh session.
        if (id === road.currentBusinessUnitId) return;

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
            [csrfHeaderName]: readXsrfToken(csrfCookieName),
          },
          credentials: "same-origin",
          body: JSON.stringify({ id }),
        })
          .then((res) => {
            if (!res.ok) {
              throw new Error(
                `[@b1-road/laravel-react] business-unit select failed: ${res.status} ${res.statusText}`,
              );
            }
            // Only re-sync the shared props once the server accepted the change.
            router.reload();
          })
          .catch((err) => {
            // Surface the failure instead of silently reverting on reload.
            console.error(err);
          });
      }}
    >
      {children}
    </RoadProvider>
  );
}

/** Escape hatch — read the `road` shared props directly. */
export function useRoadInertia(): RoadInertiaProps {
  const road = usePage<PageProps>().props.road;
  if (!road) {
    throw new Error(
      "[@b1-road/laravel-react] The `road` shared prop is missing — is " +
        "ShareRoadContext active? Run `php artisan road:doctor`.",
    );
  }
  return road;
}
