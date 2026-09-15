import {apiClient, extractData, type LaravelCategory, type LaravelProduct} from "@/lib/api/client";
import type {Category, Product} from "./catalog";

/**
 * Public storefront settings served by Laravel
 * `GET {NEXT_PUBLIC_API_URL}/v1/settings` (no auth required).
 *
 * Laravel wraps the payload in `{ data: { ... } }`, so we extract the
 * `data` field to return the raw settings object directly.
 */
export const getPublicSettings = (): Promise<ShopSettings> =>
  apiClient.get<ShopSettings>("/v1/settings").then(extractData);

/**
 * Public categories served by Laravel
 * `GET {NEXT_PUBLIC_API_URL}/v1/catalog/categories` (no auth required).
 *
 * Laravel returns `{ data: CategoryResource[] }`; we extract the array.
 */
export const getCategories = (): Promise<LaravelCategory[]> =>
  apiClient.get<LaravelCategory[]>("/v1/catalog/categories").then(extractData);

/**
 * Featured products served by Laravel
 * `GET {NEXT_PUBLIC_API_URL}/v1/catalog/products?featured=1` (no auth required).
 *
 * Laravel returns `{ data: ProductPublicResource[] }`; we extract the array.
 */
export const getFeaturedProducts = (): Promise<LaravelProduct[]> =>
  apiClient.get<LaravelProduct[]>("/v1/catalog/products?featured=1").then(extractData);

/**
 * Full product catalog served by Laravel with pagination.
 * `GET {NEXT_PUBLIC_API_URL}/v1/catalog/products` (no auth required).
 *
 * Laravel returns `{ data: { data: ProductPublicResource[], meta: pagination } }`;
 * we extract both the data array and pagination metadata.
 */
export const getProducts = (params?: {
  category_id?: number;
  category_slug?: string;
  featured?: boolean;
  search?: string;
  sort?: string;
  per_page?: number;
  page?: number;
}): Promise<{ products: LaravelProduct[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> => {
  const searchParams = new URLSearchParams();
  if (params?.category_id) searchParams.set("category_id", String(params.category_id));
  if (params?.category_slug) searchParams.set("category_slug", params.category_slug);
  if (params?.featured) searchParams.set("featured", "1");
  if (params?.search) searchParams.set("search", params.search);
  if (params?.sort) searchParams.set("sort", params.sort);
  if (params?.per_page) searchParams.set("per_page", String(params.per_page));
  if (params?.page) searchParams.set("page", String(params.page));
  const query = searchParams.toString() ? `?${searchParams.toString()}` : "";
  return apiClient.get(`/v1/catalog/products${query}`).then((res) => {
    // Laravel envelope: { data: { data: [...], meta: {...} }, meta?:... }
    const payload = (res as any).data?.data ?? res.data ?? res;
    const meta = (res as any).data?.meta ?? (res as any).meta ?? {};
    return {
      products: payload as LaravelProduct[],
      meta: {
        current_page: meta.current_page ?? 1,
        last_page: meta.last_page ?? 1,
        per_page: meta.per_page ?? 15,
        total: meta.total ?? 0,
      },
    };
  });
};

/**
 * Banners (no backend route yet)
 */
export const getBanners = (): Promise<unknown[]> =>
  apiClient.get<unknown[]>("/banners").then(extractData); // TODO: no backend route yet (banners table exists, API pending)

export type ShopFeature = {id: string; title: string; desc: string; icon: string};
export type ShopStat = {value: number; suffix: string; label: string};
export type ShopSettings = {
  store: {name: string; phone: string | null; logo: string | null; footer_logo: string | null; fav_icon: string | null; copyright_text: string | null; currency: string};
  header: Record<string, unknown> | null;
  footer: Record<string, unknown> | null;
  features: ShopFeature[];
  stats: ShopStat[];
  ticker_items: string[];
  free_shipping_threshold: number;
  currency: string;
  maintenance_mode: boolean;
};

/* =========================================================================
 * Adapters: Laravel resource shapes → frontend domain types
 * ========================================================================= */

/**
 * Map a backend category slug to the existing frontend category id.
 * The static fallback catalog uses stable ids (`coffee`, `grinders`,
 * `cooling`, `cooking`, `frying`, `bakery`, `drinks`); the backend
 * exposes only `slug`/`name`. We fall back to the slug when no static
 * mapping is known so the UI still renders without crashing.
 */
const SLUG_TO_FRONTEND_ID: Record<string, string> = {
  coffee: "coffee",
  grinders: "grinders",
  cooling: "cooling",
  cooking: "cooking",
  frying: "frying",
  bakery: "bakery",
  drinks: "drinks",
};

/** Static image fallback per frontend category id. */
const CATEGORY_IMAGE: Record<string, string> = {
  coffee: "/images/espresso.png",
  grinders: "/images/grinder.png",
  cooling: "/images/icemaker.png",
  cooking: "/images/oven.png",
  frying: "/images/fryer.png",
  bakery: "/images/mixer.png",
  drinks: "/images/juice.png",
};

/** Static tint fallback per frontend category id. */
const CATEGORY_TINT: Record<string, string> = {
  coffee: "#FF6A00",
  grinders: "#062B6F",
  cooling: "#0B4EA2",
  cooking: "#D65600",
  frying: "#B44A00",
  bakery: "#1459B8",
  drinks: "#FF862E",
};

/**
 * Convert a Laravel CategoryResource into the frontend Category type.
 * Preserves static fallbacks for `image`/`tint` when the backend does
 * not supply them, and maps the backend slug to the frontend id.
 */
export function adaptCategory(raw: LaravelCategory): Category {
  const frontendId = SLUG_TO_FRONTEND_ID[raw.slug] ?? raw.slug;
  return {
    id: frontendId,
    name: raw.name,
    desc: raw.description ?? "",
    image: raw.image_url ?? CATEGORY_IMAGE[frontendId] ?? "/images/hero.png",
    tint: CATEGORY_TINT[frontendId] ?? "#0B4EA2",
  };
}

/**
 * Pick the first active, sellable variant from a Laravel product.
 * The backend ProductVariantPublicResource exposes `price` as a numeric
 * float; we prefer a sellable variant (price > 0) and fall back to the
 * first active one.
 */
function pickDefaultVariant(raw: LaravelProduct): { id: number; sku?: string | null; name?: string | null; price: number; compare_at_price?: number | null; weight_grams?: number | null; dimensions?: Record<string, string> | null; is_active: boolean; } | null {
  const variants = raw.variants ?? [];
  const sellable = variants.find((v) => v.is_active && v.price > 0);
  return sellable ?? variants.find((v) => v.is_active) ?? variants[0] ?? null;
}

/**
 * Convert a Laravel ProductPublicResource into the frontend Product type.
 * Derives `price`/`oldPrice` from the default variant, `image` from the
 * product media, and preserves static fallback metadata for fields the
 * backend does not expose (rating, reviews, badge, freeShipping).
 */
export function adaptProduct(raw: LaravelProduct): Product {
  const variant = pickDefaultVariant(raw);
  const price = variant ? Number(variant.price) : 0;
  const oldPrice =
    variant && variant.compare_at_price != null && Number(variant.compare_at_price) > price
      ? Number(variant.compare_at_price)
      : undefined;

  const mediaUrl = raw.media?.find((m) => m.url)?.url ?? undefined;
  const frontendCategory = raw.category
    ? SLUG_TO_FRONTEND_ID[raw.category.slug] ?? raw.category.slug
    : "coffee";

  return {
    id: raw.public_id,
    name: raw.name,
    spec: raw.short_description ?? raw.description ?? "",
    price,
    oldPrice,
    image: mediaUrl ?? CATEGORY_IMAGE[frontendCategory] ?? "/images/hero.png",
    category: frontendCategory,
    freeShipping: undefined,
    startsFrom: undefined,
    badge: undefined,
    rating: 0,
    reviews: 0,
  };
}

export type { LaravelCategory, LaravelProduct };
