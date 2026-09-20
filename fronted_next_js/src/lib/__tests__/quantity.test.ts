/**
 * Regression tests for the extracted quantity helpers.
 *
 * These guard the refactor that moved `clampQuantity` / `QUANTITY_MAX_HARD`
 * out of `features/product-detail/schemas.ts` (which imports `zod`) into this
 * zod-free module, so the home page's cart store no longer drags the ~60 KB
 * zod runtime into the client bundle. The behaviour must be byte-for-byte
 * identical to what the PDP schemas relied on before.
 */
import { test } from "node:test";
import assert from "node:assert/strict";

import {
  clampQuantity,
  QUANTITY_MAX_HARD,
  QUANTITY_MIN,
  quantityUpperBound,
} from "@/lib/quantity";

test("QUANTITY bounds are the documented hard limits", () => {
  assert.equal(QUANTITY_MIN, 1);
  assert.equal(QUANTITY_MAX_HARD, 99);
});

test("quantityUpperBound caps at the hard limit", () => {
  // A stray backend stock value must not allow a 5-digit quantity.
  assert.equal(quantityUpperBound(99_999), QUANTITY_MAX_HARD);
  assert.equal(quantityUpperBound(10), 10);
});

test("quantityUpperBound never falls below the minimum (pre-order items)", () => {
  assert.equal(quantityUpperBound(0), QUANTITY_MIN);
  assert.equal(quantityUpperBound(-5), QUANTITY_MIN);
});

test("clampQuantity clamps an oversized value to stock", () => {
  assert.equal(clampQuantity(50, 10), 10);
});

test("clampQuantity clamps an undersized value to the minimum", () => {
  assert.equal(clampQuantity(0, 10), QUANTITY_MIN);
});

test("clampQuantity truncates fractional input", () => {
  assert.equal(clampQuantity(3.9, 10), 3);
});

test("clampQuantity falls back to the minimum for NaN", () => {
  assert.equal(clampQuantity(Number.NaN, 10), QUANTITY_MIN);
});

test("clampQuantity never exceeds the hard cap", () => {
  assert.equal(clampQuantity(99_999, 99_999), QUANTITY_MAX_HARD);
});

test("clampQuantity respects a max below the hard cap", () => {
  // The cart store uses this path when the PDP passes real inventory.
  assert.equal(clampQuantity(100, 4), 4);
  assert.equal(clampQuantity(2, 4), 2);
});
