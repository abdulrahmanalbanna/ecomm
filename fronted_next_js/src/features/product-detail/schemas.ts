/**
 * Zod schemas for the Product Detail Page.
 *
 * These schemas are the single source of truth for client-side validation
 * (React Hook Form via `@hookform/resolvers/zod`) AND for the request body
 * shape sent to the Laravel API, so the two can never drift.
 *
 * Backend contract (Reviews module):
 *   POST /api/v1/reviews/products/{uuid}/reviews
 *   body: { rating: int 1..5, title: string 3..100, comment: string 10..1000 }
 */

import { z } from "zod";

/* ------------------------------------------------------------------ */
/* Review submission                                                   */
/* ------------------------------------------------------------------ */

export const REVIEW_RATING_MIN = 1;
export const REVIEW_RATING_MAX = 5;
export const REVIEW_TITLE_MIN = 3;
export const REVIEW_TITLE_MAX = 100;
export const REVIEW_COMMENT_MIN = 10;
export const REVIEW_COMMENT_MAX = 1000;

export const reviewSchema = z.object({
  rating: z
    .number({ message: "pdp.reviews.errors.ratingMin" })
    .min(REVIEW_RATING_MIN, "pdp.reviews.errors.ratingMin")
    .max(REVIEW_RATING_MAX, "pdp.reviews.errors.ratingMin"),
  title: z
    .string()
    .trim()
    .min(REVIEW_TITLE_MIN, "pdp.reviews.errors.titleMin")
    .max(REVIEW_TITLE_MAX, "pdp.reviews.errors.titleMax"),
  comment: z
    .string()
    .trim()
    .min(REVIEW_COMMENT_MIN, "pdp.reviews.errors.commentMin")
    .max(REVIEW_COMMENT_MAX, "pdp.reviews.errors.commentMax"),
});

export type ReviewFormValues = z.infer<typeof reviewSchema>;

/**
 * Initial values for `react-hook-form`. `rating` starts at 0 which fails
 * validation until the user picks stars — the intended UX for a required
 * rating input.
 */
export const REVIEW_FORM_DEFAULTS: ReviewFormValues = {
  rating: 0,
  title: "",
  comment: "",
};

/* ------------------------------------------------------------------ */
/* Quantity selector                                                   */
/* ------------------------------------------------------------------ */

/**
 * Bounds + clamping live in `@/lib/quantity` (no `zod` dependency) so the
 * Zustand cart store can reuse them on the home page without pulling the
 * ~60 KB zod runtime into that bundle. Re-exported here so the PDP keeps a
 * single import surface.
 */
import {
  clampQuantity,
  QUANTITY_MAX_HARD,
  QUANTITY_MIN,
  quantityUpperBound,
} from "@/lib/quantity";
export { clampQuantity, QUANTITY_MAX_HARD, QUANTITY_MIN, quantityUpperBound };

/**
 * Build a quantity schema bound to the product's real inventory.
 *
 * @param max units available (or the hard cap, whichever is lower)
 */
export function quantitySchema(max: number) {
  const upper = quantityUpperBound(max);
  return z
    .number({ message: "pdp.actions.minQuantity" })
    .int("pdp.actions.minQuantity")
    .min(QUANTITY_MIN, "pdp.actions.minQuantity")
    .max(upper, `pdp.actions.maxQuantity:${upper}`);
}

/* ------------------------------------------------------------------ */
/* Variant selection                                                    */
/* ------------------------------------------------------------------ */

export const variantSelectionSchema = z.record(z.string(), z.string());

export type VariantSelection = z.infer<typeof variantSelectionSchema>;
