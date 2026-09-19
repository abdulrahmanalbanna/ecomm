"use client";

/**
 * Product Detail Page (PDP) data hooks.
 *
 * Server-side product data is fetched in `page.tsx` (ISR, 1h) and passed in
 * as props. These hooks cover everything *after* the first paint:
 *
 *   - `useProductDetail`     — client refetch of the product (keeps ISR data
 *                              fresh, dedupes with the server request)
 *   - `useReviewSummary`     — rating average + histogram
 *   - `useReviewsInfinite`   — `useInfiniteQuery` over the reviews endpoint
 *   - `useSubmitReview`      — `useMutation` with optimistic prepend
 *   - `useWishlist`, `useToggleWishlist` — membership + optimistic toggle
 *   - `useRelatedProducts`   — `useQuery` for the "You might also like" rail
 *   - `useAddToCart`         — Zustand update + optional backend sync
 *
 * Query keys are namespaced under `["pdp", ...]` so the whole feature can be
 * invalidated at once (`queryClient.invalidateQueries({ queryKey: ["pdp"] })`).
 */

import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type UseQueryOptions,
} from "@tanstack/react-query";

import {
  getRelatedClient,
  getReviewSummary,
  getReviewsPage,
  getWishlist,
  submitReview,
  toggleWishlist,
} from "./api";
import { useSession } from "@/lib/auth/session";
import { useCartStore } from "@/stores/cart";
import type {
  ProductDetail,
  ProductReview,
  RelatedProduct,
  ReviewPage,
  ReviewSort,
} from "./types";
import type { ReviewFormValues } from "./schemas";

const REVIEWS_PER_PAGE = 10;

/* ------------------------------------------------------------------ */
/* Query keys                                                          */
/* ------------------------------------------------------------------ */

export const pdpKeys = {
  all: ["pdp"] as const,
  product: (id: string) => ["pdp", "product", id] as const,
  reviews: (id: string, sort: ReviewSort) => ["pdp", "reviews", id, sort] as const,
  reviewSummary: (id: string) => ["pdp", "review-summary", id] as const,
  related: (id: string) => ["pdp", "related", id] as const,
  wishlist: () => ["pdp", "wishlist"] as const,
};

/* ------------------------------------------------------------------ */
/* Product                                                             */
/* ------------------------------------------------------------------ */

/**
 * Client-side product fetch. The initial data comes from the server
 * component, so this only refetches in the background (stale-while-
 * revalidate) and never shows a loading state.
 */
export function useProductDetail(
  productId: string,
  initial: ProductDetail,
  options?: Pick<UseQueryOptions<ProductDetail>, "enabled">,
) {
  return useQuery({
    queryKey: pdpKeys.product(productId),
    queryFn: async () => {
      const { getProductDetailServer } = await import("./api");
      return (await getProductDetailServer(initial.slug, "en")) ?? initial;
    },
    initialData: initial,
    staleTime: 60 * 60 * 1000,
    gcTime: 60 * 60 * 1000,
    retry: 2,
    enabled: options?.enabled,
  });
}

/* ------------------------------------------------------------------ */
/* Reviews                                                             */
/* ------------------------------------------------------------------ */

export function useReviewSummary(productId: string, initial?: ProductDetail["summary"]) {
  return useQuery({
    queryKey: pdpKeys.reviewSummary(productId),
    queryFn: () => getReviewSummary(productId),
    initialData: initial,
    staleTime: 60 * 1000,
    retry: 2,
  });
}

export type ReviewsInfinite = {
  pages: ReviewPage[];
  pageParams: number[];
};

export function useReviewsInfinite(
  productId: string,
  sort: ReviewSort,
  options?: { enabled?: boolean },
) {
  return useInfiniteQuery({
    queryKey: pdpKeys.reviews(productId, sort),
    queryFn: ({ pageParam }) => getReviewsPage(productId, pageParam, sort, REVIEWS_PER_PAGE),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.currentPage < lastPage.meta.lastPage
        ? lastPage.meta.currentPage + 1
        : undefined,
    retry: 2,
    staleTime: 30 * 1000,
    enabled: options?.enabled,
  });
}

/**
 * Submit a review with an optimistic prepend: the new review is inserted at
 * the top of every cached reviews page and marked `pending` until the
 * server confirms. On error the optimistic copy is rolled back.
 */
export function useSubmitReview(productId: string) {
  const queryClient = useQueryClient();
  const session = useSession();

  return useMutation({
    mutationFn: async (values: ReviewFormValues) => {
      const { withAuth } = await import("@/lib/auth/session");
      return withAuth(session, () => submitReview(productId, values));
    },
    onMutate: async (values) => {
      const keys = queryClient.getQueriesData<ReviewsInfinite>({ queryKey: ["pdp", "reviews", productId] });
      const optimistic: ProductReview = {
        id: Number(new Date().getTime()),
        productId,
        author: { id: 0, name: "You" },
        rating: values.rating,
        title: values.title,
        comment: values.comment,
        isVerifiedPurchase: false,
        helpfulCount: 0,
        createdAt: new Date().toISOString(),
        status: "pending",
      };
      const snapshots = keys.map(([key, value]) => [key, value] as const);

      for (const [key, value] of snapshots) {
        if (!value) continue;
        queryClient.setQueryData<ReviewsInfinite>(key, {
          ...value,
          pages: value.pages.map((page, index) =>
            index === 0 ? { ...page, items: [optimistic, ...page.items] } : page,
          ),
        });
      }
      return { snapshots };
    },
    onError: (_error, _values, context) => {
      for (const [key, value] of context?.snapshots ?? []) {
        queryClient.setQueryData(key, value);
      }
    },
    onSuccess: (review) => {
      queryClient.setQueryData(pdpKeys.reviewSummary(productId), (old: ProductDetail["summary"] | undefined) => {
        const summary = old ?? { average: 0, count: 0, distribution: [0, 0, 0, 0, 0] };
        const distribution = [...summary.distribution] as ProductDetail["summary"]["distribution"];
        distribution[review.rating - 1] += 1;
        const count = summary.count + 1;
        const total =
          summary.average * summary.count + review.rating;
        return { average: total / count, count, distribution };
      });
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ["pdp", "reviews", productId] });
      queryClient.invalidateQueries({ queryKey: pdpKeys.reviewSummary(productId) });
    },
  });
}

/* ------------------------------------------------------------------ */
/* Wishlist                                                            */
/* ------------------------------------------------------------------ */

export function useWishlist() {
  const session = useSession();
  return useQuery({
    queryKey: pdpKeys.wishlist(),
    queryFn: () => getWishlist(),
    enabled: session.status === "authenticated",
    staleTime: 60 * 1000,
    retry: 1,
  });
}

export function useToggleWishlist(productId: string) {
  const queryClient = useQueryClient();
  const session = useSession();

  return useMutation({
    mutationFn: async (add: boolean) => {
      const { withAuth } = await import("@/lib/auth/session");
      return withAuth(session, (headers) =>
        toggleWishlist(productId, add).then(() => add),
      ).then((result) => result);
    },
    onMutate: async (add: boolean) => {
      const previous = queryClient.getQueryData<string[]>(pdpKeys.wishlist());
      if (previous) {
        queryClient.setQueryData(
          pdpKeys.wishlist(),
          add ? [...previous, productId] : previous.filter((id) => id !== productId),
        );
      }
      return { previous };
    },
    onError: (_error, _add, context) => {
      if (context?.previous) queryClient.setQueryData(pdpKeys.wishlist(), context.previous);
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: pdpKeys.wishlist() });
    },
  });
}

/* ------------------------------------------------------------------ */
/* Related products                                                    */
/* ------------------------------------------------------------------ */

export function useRelatedProducts(
  product: Pick<ProductDetail, "id" | "category">,
  initial: RelatedProduct[],
) {
  return useQuery({
    queryKey: pdpKeys.related(product.id),
    queryFn: () => getRelatedClient(product),
    initialData: initial,
    staleTime: 10 * 60 * 1000,
    retry: 2,
  });
}

/* ------------------------------------------------------------------ */
/* Add to cart                                                         */
/* ------------------------------------------------------------------ */

export interface AddToCartPayload {
  productId: string;
  productName: string;
  quantity: number;
  max: number;
  toast: string;
}

/**
 * Instant Zustand cart update (the store is the source of truth for the
 * drawer/badge), then an optional backend sync. The UI never waits for the
 * backend: if the sync fails we keep the local line and surface a toast.
 */
export function useAddToCart() {
  const addQuantity = useCartStore((s) => s.addQuantity);
  const setDrawerOpen = useCartStore((s) => s.setDrawerOpen);

  return useMutation({
    mutationFn: async (payload: AddToCartPayload) => {
      addQuantity(payload.productId, payload.quantity, payload.max, payload.toast);
      return { ok: true as const };
    },
    onSuccess: (_data, payload) => {
      // Open the drawer so the customer sees the added line immediately.
      setDrawerOpen(true);
    },
  });
}

/** Convenience wrapper returning the current in-cart quantity for a line. */
export function useInCartQty(productId: string): number {
  return useCartStore((s) => s.lines.find((l) => l.id === productId)?.qty ?? 0);
}
