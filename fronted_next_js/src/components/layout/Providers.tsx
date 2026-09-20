import type { ReactNode } from "react";

/**
 * App-wide client providers.
 *
 * NOTE: `@tanstack/react-query` is intentionally NOT mounted here. It is only
 * used by the Product Detail Page feature (`src/features/product-detail/hooks.ts`),
 * and mounting `QueryClientProvider` at the root shipped the whole react-query
 * runtime (~40 KB across three chunks) to every route — including the home
 * page, which never calls `useQuery`. The PDP wraps itself in
 * `ProductDetailProviders` instead, so react-query is code-split to the route
 * that actually needs it.
 *
 * Kept as a client component (and a real component, not a fragment) so the
 * tree stays stable and other global providers can be added here later
 * without touching the root layout.
 */
export function Providers({ children }: { children: ReactNode }) {
  return <>{children}</>;
}
