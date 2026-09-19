/**
 * Product Detail Page (PDP) domain types.
 *
 * These are the *frontend* shapes used by the PDP UI. They are produced by
 * the adapters in `./api.ts`, which map the Laravel
 * `ProductPublicResource` / `ProductVariantPublicResource` payloads (see
 * `LaravelProduct` / `LaravelProductVariant` in `@/lib/api/client`) onto
 * render-ready objects.
 *
 * Convention: every field is optional-safe (no `!` assertions downstream)
 * because the catalog API may omit fields for legacy rows.
 */

/** A single gallery image (backend `media[]` entry, resolved to an absolute URL). */
export interface ProductImage {
  id: string;
  url: string;
  alt: string;
  /** Media kind; only `image` entries are rendered in the gallery. */
  type: "image" | "video" | "other";
}

/** A sellable variant (backend `variants[]` entry). */
export interface ProductVariant {
  id: number;
  sku: string | null;
  name: string | null;
  price: number;
  compareAtPrice: number | null;
  weightGrams: number | null;
  dimensions: Record<string, string> | null;
  isActive: boolean;
  /** Attribute values that distinguish this variant, e.g. `{ color: "Red", size: "L" }`. */
  attributes: Record<string, string>;
  /** Per-variant stock when the backend exposes it (optional). */
  stock?: number | null;
}

/** A named attribute with the values available on the product (used by the variant selector). */
export interface ProductAttributeOption {
  name: string;
  value: string;
}

export interface ProductAttribute {
  id: number;
  name: string;
  code: string | null;
  options: ProductAttributeOption[];
}

/** Inventory availability state drives the CTA + urgency messaging. */
export type Availability = "in-stock" | "low-stock" | "out-of-stock" | "preorder";

export interface ProductStock {
  availability: Availability;
  /** Units available for immediate purchase (0 when out of stock). */
  quantity: number;
  /** Units already reserved by other carts; used for urgency messaging. */
  reserved?: number;
  /** Lead time in days for pre-orders. */
  leadTimeDays?: number;
}

export interface ProductReviewSummary {
  /** Mean rating across approved reviews (0 when none). */
  average: number;
  /** Number of approved reviews. */
  count: number;
  /** Distribution histogram: index 0 = 1 star … index 4 = 5 stars. */
  distribution: [number, number, number, number, number];
}

/**
 * The full product detail payload consumed by the PDP.
 *
 * `summary` is derived: the backend does not expose review aggregates on the
 * product resource yet, so the adapter falls back to `0`/empty and the
 * reviews section hydrates it client-side via `useReviewSummary`.
 */
export interface ProductDetail {
  id: string;
  slug: string;
  name: string;
  description: string;
  shortDescription: string;
  brand: { id: number; name: string; slug: string | null } | null;
  category: { id: string; name: string; slug: string } | null;
  images: ProductImage[];
  variants: ProductVariant[];
  attributes: ProductAttribute[];
  specifications: Array<{ label: string; value: string }>;
  tags: string[];
  isFeatured: boolean;
  seoTitle: string;
  seoDescription: string;
  stock: ProductStock;
  summary: ProductReviewSummary;
  /** Absolute URL of the first gallery image, for OG/Twitter cards. */
  primaryImage: string;
  /** Canonical site URL of this PDP (locale-aware). */
  url: string;
  createdAt?: string;
}

/* ------------------------------------------------------------------ */
/* Reviews                                                             */
/* ------------------------------------------------------------------ */

export interface ReviewAuthor {
  id: number;
  name: string;
  /** Avatar URL when the backend exposes one. */
  avatarUrl?: string | null;
}

export interface ProductReview {
  id: number;
  productId: string;
  author: ReviewAuthor;
  rating: number;
  title: string;
  comment: string;
  isVerifiedPurchase: boolean;
  helpfulCount: number;
  createdAt: string;
  /** Presentational only: optimistic reviews start as `pending`. */
  status?: "pending" | "approved";
}

export type ReviewSort = "newest" | "helpful" | "highest" | "lowest";

/** Cursor-free page metadata returned by the reviews endpoint. */
export interface ReviewPageMeta {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
}

export interface ReviewPage {
  items: ProductReview[];
  meta: ReviewPageMeta;
}

/* ------------------------------------------------------------------ */
/* Related products                                                    */
/* ------------------------------------------------------------------ */

/** Compact card payload for the "You might also like" rail + comparison table. */
export interface RelatedProduct {
  id: string;
  slug: string;
  name: string;
  price: number;
  compareAtPrice: number | null;
  image: string;
  brand: string | null;
  category: string | null;
  rating: number;
  reviewCount: number;
  availability: Availability;
}

/* ------------------------------------------------------------------ */
/* Wishlist                                                            */
/* ------------------------------------------------------------------ */

export interface WishlistItem {
  productId: string;
  createdAt: string;
}
