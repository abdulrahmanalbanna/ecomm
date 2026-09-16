"use client";

import { useEffect, useRef, useState } from "react";
import { getCategories, getProducts } from "@/features/home/api";
import { adaptCategory, adaptProduct } from "@/features/home/api";

import {
  categories as staticCategories,
  products as staticProducts,
  type Category,
  type Product,
} from "./catalog";

/**
 * Single shared live catalog snapshot.
 *
 * Backend layout:
 *   GET /v1/catalog/categories → { data: CategoryResource[] }
 *   GET /v1/catalog/products   → { data: { data: ProductPublicResource[], meta: { current_page, last_page, per_page, total } } }
 *
 * We fetch both endpoints in parallel after hydration, fall back to the
 * static catalog on error, and expose a `byId`/`byCategory` lookup map
 * keyed by the same identity used by the local cart (the backend
 * `public_id` UUID). During the first server render we return the
 * static data so the initial HTML matches the hydration payload and
 * React does not warn about mismatch.
 *
 * The hook is intentionally lightweight (no context provider): a single
 * request is issued from `HomePage` and the resulting snapshot is passed
 * via props to all child sections (`CategoryTiles`, `ProductRail`,
 * `TabsSection`, `Header`, `SearchBox`, `ProductCard`, cart store).
 */
export type CatalogState = {
  categories: Category[];
  products: Product[];
  byId: Map<string, Product>;
  byCategory: Map<string, Product[]>;
  live: boolean;
  loading: boolean;
  error: string | null;
};

function buildLookups(products: Product[]) {
  const byId = new Map<string, Product>();
  const byCategory = new Map<string, Product[]>();
  for (const p of products) {
    byId.set(p.id, p);
    const list = byCategory.get(p.category);
    if (list) list.push(p);
    else byCategory.set(p.category, [p]);
  }
  return { byId, byCategory };
}

const STATIC_LOOKUPS = buildLookups(staticProducts);

let inflight: Promise<void> | null = null;
let snapshot: { categories: Category[]; products: Product[] } | null = null;
const subscribers = new Set<() => void>();

function notify() {
  for (const cb of subscribers) cb();
}

async function loadOnce(): Promise<void> {
  if (inflight) return inflight;
  inflight = (async () => {
    try {
      // Fetch the first page of products (per_page=100) + categories in parallel.
      const [{ products: laravelProducts }, laravelCategories] = await Promise.all([
        getProducts({ per_page: 100, page: 1 }),
        getCategories(),
      ]);

      const adaptedCats = laravelCategories.length > 0
        ? laravelCategories.map(adaptCategory)
        : staticCategories;
      const adaptedProds = laravelProducts.length > 0
        ? laravelProducts.map(adaptProduct)
        : staticProducts;

      snapshot = { categories: adaptedCats, products: adaptedProds };
    } catch {
      // Keep the static snapshot in place on failure so the UI still renders.
      snapshot = null;
    } finally {
      inflight = null;
      notify();
    }
  })();
  return inflight;
}

export function getCatalogSnapshot() {
  return snapshot;
}

export function useCatalog(): CatalogState {
  // Initial state mirrors the static fallback (used by SSR + first paint).
  const [, force] = useState(0);
  const mountedRef = useRef(false);

  useEffect(() => {
    mountedRef.current = true;
    const cb = () => force((n) => n + 1);
    subscribers.add(cb);
    // Kick off a single shared load if we don't have a snapshot yet.
    if (!snapshot && !inflight) {
      void loadOnce();
    }
    return () => {
      subscribers.delete(cb);
      mountedRef.current = false;
    };
  }, []);

  const categories = snapshot?.categories ?? staticCategories;
  const products = snapshot?.products ?? staticProducts;
  const lookups = snapshot
    ? buildLookups(products)
    : STATIC_LOOKUPS;

  return {
    categories,
    products,
    byId: lookups.byId,
    byCategory: lookups.byCategory,
    live: snapshot !== null,
    loading: inflight !== null && snapshot === null,
    error: null,
  };
}
