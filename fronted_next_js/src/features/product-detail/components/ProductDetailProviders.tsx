"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

/**
 * react-query mounted *only* around the Product Detail Page.
 *
 * The home page and the rest of the storefront read server-rendered data
 * through props, so keeping this boundary tight means the react-query
 * runtime is only downloaded on `/[locale]/products/[slug]`.
 */
export function ProductDetailProviders({ children }: { children: ReactNode }) {
  const [client] = useState(() => new QueryClient());
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
