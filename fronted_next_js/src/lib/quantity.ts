/**
 * Quantity bounds and clamping, free of any validation-library dependency.
 *
 * This lives in `src/lib` (not `features/product-detail/schemas.ts`) on
 * purpose: the Zustand cart store needs `clampQuantity` on the home page,
 * and importing it from the PDP schemas file would drag `zod` (~60 KB) into
 * the home client bundle. The PDP schemas re-export these values so the zod
 * side stays the single source of truth for validation.
 */

export const QUANTITY_MIN = 1;
/** Safety cap so a stray backend `stock` value can't render a 9999-row input. */
export const QUANTITY_MAX_HARD = 99;

/**
 * Upper bound shared by the zod schema and the imperative clamp: the real
 * inventory, capped at the hard limit, and never below the minimum
 * (so a 0-stock pre-order item can still be ordered once).
 */
export function quantityUpperBound(max: number): number {
  return Math.max(QUANTITY_MIN, Math.min(max, QUANTITY_MAX_HARD));
}

/** Clamp a user-supplied quantity into the valid `[1, max]` range. */
export function clampQuantity(value: number, max: number): number {
  const upper = quantityUpperBound(max);
  if (Number.isNaN(value)) return QUANTITY_MIN;
  return Math.min(Math.max(Math.trunc(value), QUANTITY_MIN), upper);
}
