/**
 * Product Detail Page (PDP) API layer.
 *
 * Server-side entry points (`getProductDetailServer`, `getRelatedServer`)
 * are called from `app/[locale]/(store)/products/[slug]/page.tsx` with
 * `next: { revalidate }` (ISR, 1 hour for product data).
 *
 * Client-side hooks in `./hooks.ts` use the same `apiClient` for reviews
 * (infinite query), review submission, wishlist and related products.
 *
 * Backend contract (Catalog module, public routes):
 *   GET  /api/v1/catalog/products/{publicId|slug}     → { data: ProductPublicResource }
 *   GET  /api/v1/catalog/products?category_slug=…     → { data: { data: […], meta } }
 *
 * The Reviews/Wishlist endpoints are implemented defensively: when the
 * module is not deployed (404), the UI degrades to an empty state instead
 * of crashing. See `docs/product-detail-page.md`.
 */

import { apiClient, extractData, type LaravelProduct, type LaravelProductVariant } from "@/lib/api/client";
import { resolveMediaUrl } from "@/lib/media";
import { branding } from "@/config/branding";
import type { Locale } from "@/types/locale";
import type {
  Availability,
  ProductAttribute,
  ProductDetail,
  ProductImage,
  ProductReview,
  ProductStock,
  ProductVariant,
  RelatedProduct,
  ReviewPage,
  ReviewPageMeta,
  ReviewSort,
} from "./types";

/** Revalidate product data every hour (ISR). Reviews are fetched live. */
export const PRODUCT_REVALIDATE_SECONDS = 3600;

const LOW_STOCK_THRESHOLD = 10;
const PREORDER_LEAD_DAYS = 7;

/* ------------------------------------------------------------------ */
/* Raw backend shapes (not exported — adapted immediately)             */
/* ------------------------------------------------------------------ */

interface LaravelReview {
  id: number;
  product_id?: string;
  customer?: { id: number; name: string; avatar_url?: string | null } | null;
  author_name?: string | null;
  rating: number;
  title?: string | null;
  comment?: string | null;
  body?: string | null;
  is_verified_purchase?: boolean;
  verified?: boolean;
  helpful_count?: number;
  created_at?: string;
  status?: string;
}

interface LaravelReviewResponse {
  data: LaravelReview[];
  meta?: {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
  };
}

interface LaravelReviewSummary {
  average_rating?: number;
  rating_average?: number;
  reviews_count?: number;
  total_reviews?: number;
  rating_distribution?: Record<string, number>;
  distribution?: Record<string, number>;
}

/* ------------------------------------------------------------------ */
/* Adapters                                                            */
/* ------------------------------------------------------------------ */

function toAvailability(stock: number | null | undefined): ProductStock {
  if (stock == null) {
    // Unknown inventory (backend did not expose it) → assume buyable.
    return { availability: "in-stock", quantity: 0 };
  }
  if (stock <= 0) {
    // Zero units on hand. The backend has no explicit `is_preorder` flag in
    // the public resource yet, so we surface the honest state: the CTA is
    // disabled. Pre-order is reserved for an explicit backend signal.
    return { availability: "out-of-stock", quantity: 0 };
  }
  if (stock <= LOW_STOCK_THRESHOLD) return { availability: "low-stock", quantity: stock };
  return { availability: "in-stock", quantity: stock };
}

function adaptImages(raw: LaravelProduct): ProductImage[] {
  const media = raw.media ?? [];
  const images = media
    .filter((m) => m.url)
    .map((m, index) => ({
      id: String(m.id ?? index),
      url: resolveMediaUrl(m.url, "hero.png"),
      alt: m.alt_text?.trim() ? m.alt_text.trim() : raw.name,
      type: (m.type === "video" ? "video" : m.type === "other" ? "other" : "image") as ProductImage["type"],
    }));
  // A product with no media still renders one placeholder frame so the
  // gallery layout is stable (matches the skeleton loader shape).
  if (images.length === 0) {
    images.push({
      id: "placeholder",
      url: resolveMediaUrl(null, "hero.png"),
      alt: raw.name,
      type: "image",
    });
  }
  return images;
}

function adaptVariants(raw: LaravelProduct): ProductVariant[] {
  // `LaravelProductVariant` is the superset shape (it also carries
  // `attributes`/`media`); the product resource inlines a narrower type.
  const variants = (raw.variants ?? []) as unknown as LaravelProductVariant[];
  return variants.map((v) => {
    const attributes: Record<string, string> = {};
    for (const attr of v.attributes ?? []) {
      if (attr.name) attributes[attr.name] = attr.code ?? attr.name;
    }
    return {
      id: v.id,
      sku: v.sku ?? null,
      name: v.name ?? null,
      price: Number(v.price) || 0,
      compareAtPrice:
        v.compare_at_price != null && Number(v.compare_at_price) > Number(v.price)
          ? Number(v.compare_at_price)
          : null,
      weightGrams: v.weight_grams ?? null,
      dimensions: v.dimensions ?? null,
      isActive: v.is_active,
      attributes,
    };
  });
}

function adaptAttributes(raw: LaravelProduct): ProductAttribute[] {
  const byName = new Map<string, ProductAttribute>();
  // Product-level attributes carry their option values inline.
  for (const attr of raw.attributes ?? []) {
    if (!attr.name) continue;
    const existing = byName.get(attr.name);
    if (existing) continue;
    byName.set(attr.name, {
      id: attr.id,
      name: attr.name,
      code: attr.code ?? null,
      options: [],
    });
  }
  // Variant-level attribute values populate the selectable options.
  const variants = (raw.variants ?? []) as unknown as LaravelProductVariant[];
  for (const variant of variants) {
    for (const attr of variant.attributes ?? []) {
      if (!attr.name) continue;
      const group = byName.get(attr.name);
      const value = attr.code ?? attr.name;
      if (group && !group.options.some((o) => o.value === value)) {
        group.options.push({ name: attr.name, value });
      }
    }
  }
  return [...byName.values()].filter((a) => a.options.length > 0);
}

function adaptSpecifications(raw: LaravelProduct): Array<{ label: string; value: string }> {
  const specs = raw.specifications ?? {};
  const entries = Object.entries(specs)
    .filter(([, value]) => value != null && String(value).trim() !== "")
    .map(([label, value]) => ({ label, value: String(value) }));
  // Enrich with variant physical data when the backend omits it.
  const variant = raw.variants?.find((v) => v.is_active) ?? raw.variants?.[0];
  if (variant?.dimensions) {
    for (const [key, value] of Object.entries(variant.dimensions)) {
      if (value == null || String(value).trim() === "") continue;
      const label = key.charAt(0).toUpperCase() + key.slice(1);
      if (!entries.some((e) => e.label.toLowerCase() === label.toLowerCase())) {
        entries.push({ label, value: String(value) });
      }
    }
  }
  if (variant?.weight_grams && !entries.some((e) => /weight/i.test(e.label))) {
    entries.push({ label: "Weight", value: `${variant.weight_grams} g` });
  }
  return entries;
}

/**
 * Convert a Laravel `ProductPublicResource` into the PDP domain object.
 *
 * `locale` + `slug` are used to build the canonical URL (for the JSON-LD
 * structured data and the share buttons).
 */
export function adaptProductDetail(
  raw: LaravelProduct,
  options: { locale: Locale; slug: string; stock?: number | null },
): ProductDetail {
  const { locale, slug, stock } = options;
  const images = adaptImages(raw);
  const variants = adaptVariants(raw);
  const activeVariant = variants.find((v) => v.isActive) ?? variants[0] ?? null;
  const rawWithDates = raw as LaravelProduct & { created_at?: string };

  return {
    id: raw.public_id,
    slug,
    name: raw.name,
    description: raw.description ?? raw.short_description ?? "",
    shortDescription: raw.short_description?.trim() ? raw.short_description : (raw.description ?? "").slice(0, 160),
    brand: raw.brand ? { id: raw.brand.id, name: raw.brand.name, slug: raw.brand.slug ?? null } : null,
    category: raw.category
      ? { id: raw.category.slug, name: raw.category.name, slug: raw.category.slug }
      : null,
    images,
    variants,
    attributes: adaptAttributes(raw),
    specifications: adaptSpecifications(raw),
    tags: (raw.tags ?? [])
      .map((t) => t.name)
      // `Boolean` alone lets a whitespace-only name through.
      .filter((name) => name.trim()),
    isFeatured: Boolean(raw.is_featured),
    seoTitle: raw.seo_title?.trim() ? raw.seo_title.trim() : raw.name,
    seoDescription: raw.seo_description?.trim()
      ? raw.seo_description.trim()
      : raw.short_description?.trim() || (raw.description ?? "").slice(0, 160),
    stock: toAvailability(stock ?? activeVariant?.stock ?? null),
    summary: { average: 0, count: 0, distribution: [0, 0, 0, 0, 0] },
    primaryImage: images[0]?.url ?? resolveMediaUrl(null, "hero.png"),
    url: `${branding.siteUrl}/${locale}/products/${encodeURIComponent(slug)}`,
    createdAt: rawWithDates.created_at,
  };
}

function adaptReview(raw: LaravelReview, productId: string): ProductReview {
  const author = raw.customer
    ? { id: raw.customer.id, name: raw.customer.name, avatarUrl: raw.customer.avatar_url ?? null }
    : { id: 0, name: raw.author_name?.trim() || "—" };
  return {
    id: raw.id,
    productId,
    author,
    rating: Math.max(1, Math.min(5, Math.round(Number(raw.rating) || 0))) || 0,
    title: (raw.title?.trim() || raw.comment?.slice(0, 60) || "").trim(),
    comment: (raw.comment?.trim() || raw.body?.trim() || "").trim(),
    isVerifiedPurchase: Boolean(raw.is_verified_purchase ?? raw.verified),
    helpfulCount: Number(raw.helpful_count ?? 0) || 0,
    createdAt: raw.created_at ?? new Date().toISOString(),
    status: raw.status === "pending" ? "pending" : "approved",
  };
}

function adaptReviewMeta(raw: LaravelReviewResponse["meta"]): ReviewPageMeta {
  return {
    currentPage: raw?.current_page ?? 1,
    lastPage: raw?.last_page ?? 1,
    perPage: raw?.per_page ?? 10,
    total: raw?.total ?? 0,
  };
}

function adaptReviewSummary(raw: LaravelReviewSummary | null | undefined) {
  const distribution = [0, 0, 0, 0, 0] as [number, number, number, number, number];
  const source = raw?.rating_distribution ?? raw?.distribution ?? {};
  for (const [key, value] of Object.entries(source)) {
    const index = Number(key) - 1;
    if (index >= 0 && index <= 4) distribution[index] = Number(value) || 0;
  }
  return {
    average: Number(raw?.average_rating ?? raw?.rating_average ?? 0) || 0,
    count: Number(raw?.reviews_count ?? raw?.total_reviews ?? 0) || 0,
    distribution,
  };
}

/* ------------------------------------------------------------------ */
/* Server-side fetchers (used by page.tsx with revalidation)           */
/* ------------------------------------------------------------------ */

interface ProductDetailResponse extends LaravelProduct {
  stock?: number | null;
  inventory?: { quantity?: number | null } | null;
  review_summary?: LaravelReviewSummary | null;
}

/**
 * Fetch a single product for the PDP.
 *
 * Returns `null` when the product does not exist (404) so `page.tsx` can
 * call `notFound()` and render the 404 page. Network/5xx errors are thrown
 * so the route `error.tsx` boundary renders a retry state.
 */
export async function getProductDetailServer(
  slug: string,
  locale: Locale,
): Promise<ProductDetail | null> {
  const encoded = encodeURIComponent(slug);
  try {
    const raw = await apiClient.get<ProductDetailResponse>(`/v1/catalog/products/${encoded}`, {
      next: { revalidate: PRODUCT_REVALIDATE_SECONDS },
    }).then(extractData);
    return adaptProductDetail(raw, {
      locale,
      slug,
      stock: raw.stock ?? raw.inventory?.quantity ?? null,
    });
  } catch (error) {
    if (error instanceof Error && "status" in error && (error as { status: number }).status === 404) {
      return null;
    }
    throw error;
  }
}

/** Related products for the "You might also like" rail (server prefetch). */
export async function getRelatedServer(
  product: Pick<ProductDetail, "id" | "category" | "slug">,
  locale: Locale,
): Promise<RelatedProduct[]> {
  const params = new URLSearchParams({ per_page: "8" });
  if (product.category) params.set("category_slug", product.category.slug);
  const query = params.toString() ? `?${params.toString()}` : "";

  try {
    const res = await apiClient.get<unknown>(`/v1/catalog/products${query}`, {
      next: { revalidate: PRODUCT_REVALIDATE_SECONDS },
    });
    const rawData = (res as { data?: { data?: LaravelProduct[] } | LaravelProduct[] }).data;
    const items: LaravelProduct[] = Array.isArray(rawData) ? rawData : (rawData?.data ?? []);
    return items
      .filter((p) => p.public_id !== product.id)
      .slice(0, 6)
      .map((p) => adaptRelated(p, locale));
  } catch {
    return [];
  }
}

function adaptRelated(raw: LaravelProduct, _locale: Locale): RelatedProduct {
  const variant = raw.variants?.find((v) => v.is_active) ?? raw.variants?.[0] ?? null;
  const price = variant ? Number(variant.price) || 0 : 0;
  const compareAt =
    variant?.compare_at_price != null && Number(variant.compare_at_price) > price
      ? Number(variant.compare_at_price)
      : null;
  const mediaUrl = raw.media?.find((m) => m.url)?.url ?? null;
  return {
    id: raw.public_id,
    slug: raw.slug,
    name: raw.name,
    price,
    compareAtPrice: compareAt,
    image: resolveMediaUrl(mediaUrl, "hero.png"),
    brand: raw.brand?.name ?? null,
    category: raw.category?.name ?? null,
    rating: 0,
    reviewCount: 0,
    availability: "in-stock" as Availability,
  };
}

/* ------------------------------------------------------------------ */
/* Client-side fetchers (TanStack Query)                               */
/* ------------------------------------------------------------------ */

const REVIEW_SORT_PARAM: Record<ReviewSort, string> = {
  newest: "newest",
  helpful: "helpful",
  highest: "rating_desc",
  lowest: "rating_asc",
};

/** Reviews page (page-based; wrapped in `useInfiniteQuery` in `./hooks.ts`). */
export async function getReviewsPage(
  productId: string,
  page: number,
  sort: ReviewSort,
  perPage = 10,
): Promise<ReviewPage> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
    sort: REVIEW_SORT_PARAM[sort],
  });
  const res = await apiClient.get<LaravelReviewResponse>(
    `/v1/reviews/products/${encodeURIComponent(productId)}/reviews?${params.toString()}`,
  );
  const raw = res.data;
  return {
    items: (raw.data ?? []).map((r) => adaptReview(r, productId)),
    meta: adaptReviewMeta(raw.meta),
  };
}

/** Review aggregates shown next to the star display. */
export async function getReviewSummary(productId: string) {
  try {
    const res = await apiClient.get<LaravelReviewSummary | null>(
      `/v1/reviews/products/${encodeURIComponent(productId)}/summary`,
    );
    return adaptReviewSummary(res.data);
  } catch {
    return adaptReviewSummary(null);
  }
}

/** Submit a review (authenticated). */
export async function submitReview(
  productId: string,
  values: { rating: number; title: string; comment: string },
): Promise<ProductReview> {
  const res = await apiClient.post<LaravelReview>(
    `/v1/reviews/products/${encodeURIComponent(productId)}/reviews`,
    values,
  );
  return adaptReview(res.data, productId);
}

/** Toggle wishlist membership (authenticated). */
export async function toggleWishlist(productId: string, add: boolean): Promise<boolean> {
  const path = `/v1/wishlist`;
  if (add) {
    await apiClient.post(`${path}`, { product_id: productId });
    return true;
  }
  await apiClient.delete(`${path}/${encodeURIComponent(productId)}`);
  return false;
}

/** Current wishlist membership for the signed-in customer. */
export async function getWishlist(): Promise<string[]> {
  try {
    const res = await apiClient.get<Array<{ product_id: string } | string>>(`/v1/wishlist`);
    const data = res.data ?? [];
    return data.map((item) => (typeof item === "string" ? item : item.product_id));
  } catch {
    return [];
  }
}

/** Related products (client refetch keeps the rail fresh after ISR). */
export async function getRelatedClient(
  product: Pick<ProductDetail, "id" | "category">,
): Promise<RelatedProduct[]> {
  const params = new URLSearchParams({ per_page: "8" });
  if (product.category) params.set("category_slug", product.category.slug);
  const query = params.toString() ? `?${params.toString()}` : "";
  const res = await apiClient.get<unknown>(`/v1/catalog/products${query}`);
  const rawData = (res as { data?: { data?: LaravelProduct[] } | LaravelProduct[] }).data;
  const items: LaravelProduct[] = Array.isArray(rawData) ? rawData : (rawData?.data ?? []);
  return items
    .filter((p) => p.public_id !== product.id)
    .slice(0, 6)
    .map((p) => adaptRelated(p, "en"));
}

/** Server-side variant of `getReviewSummary` for the initial render. */
export async function getReviewSummaryServer(productId: string) {
  return getReviewSummary(productId);
}
