/**
 * Unit tests for the PDP Zod schemas.
 *
 * Run: `npm test` (node:test via tsx).
 */

import assert from "node:assert/strict";
import { test } from "node:test";
import {
  clampQuantity,
  quantitySchema,
  QUANTITY_MAX_HARD,
  QUANTITY_MIN,
  reviewSchema,
  REVIEW_COMMENT_MAX,
  REVIEW_TITLE_MAX,
} from "../schemas";

test("reviewSchema accepts a valid review", () => {
  const result = reviewSchema.safeParse({
    rating: 5,
    title: "Excellent machine",
    comment: "Been using it for three months in our café, zero issues.",
  });

  assert.equal(result.success, true);
});

test("reviewSchema rejects a missing rating", () => {
  const result = reviewSchema.safeParse({
    rating: 0,
    title: "Decent",
    comment: "Works as expected for the price.",
  });

  assert.equal(result.success, false);
});

test("reviewSchema rejects a rating above 5", () => {
  const result = reviewSchema.safeParse({
    rating: 6,
    title: "Six stars",
    comment: "This should not be allowed by the schema.",
  });

  assert.equal(result.success, false);
});

test("reviewSchema rejects a title shorter than 3 characters", () => {
  const result = reviewSchema.safeParse({
    rating: 4,
    title: "OK",
    comment: "A perfectly valid comment body length here.",
  });

  assert.equal(result.success, false);
});

test("reviewSchema rejects a comment shorter than 10 characters", () => {
  const result = reviewSchema.safeParse({
    rating: 4,
    title: "Short comment",
    comment: "too short",
  });

  assert.equal(result.success, false);
});

test(`reviewSchema rejects a comment longer than ${REVIEW_COMMENT_MAX} characters`, () => {
  const result = reviewSchema.safeParse({
    rating: 4,
    title: "Long comment",
    comment: "x".repeat(REVIEW_COMMENT_MAX + 1),
  });

  assert.equal(result.success, false);
});

test(`reviewSchema rejects a title longer than ${REVIEW_TITLE_MAX} characters`, () => {
  const result = reviewSchema.safeParse({
    rating: 4,
    title: "x".repeat(REVIEW_TITLE_MAX + 1),
    comment: "A valid comment body.",
  });

  assert.equal(result.success, false);
});

test("reviewSchema trims whitespace before validating length", () => {
  const result = reviewSchema.safeParse({
    rating: 5,
    title: "  Padded title  ",
    comment: "  Padded comment body.  ",
  });

  assert.equal(result.success, true);
  if (result.success) {
    assert.equal(result.data.title, "Padded title");
    assert.equal(result.data.comment, "Padded comment body.");
  }
});

/* ------------------------------------------------------------------ */
/* Quantity                                                            */
/* ------------------------------------------------------------------ */

test("quantitySchema accepts a quantity within stock", () => {
  const result = quantitySchema(10).safeParse(5);
  assert.equal(result.success, true);
});

test("quantitySchema rejects zero", () => {
  const result = quantitySchema(10).safeParse(0);
  assert.equal(result.success, false);
});

test("quantitySchema rejects a quantity above available stock", () => {
  const result = quantitySchema(3).safeParse(4);
  assert.equal(result.success, false);
});

test("quantitySchema rejects a fractional quantity", () => {
  const result = quantitySchema(10).safeParse(2.5);
  assert.equal(result.success, false);
});

test(`quantitySchema caps the upper bound at the hard limit of ${QUANTITY_MAX_HARD}`, () => {
  // A stray backend stock value must not allow a 5-digit quantity.
  const result = quantitySchema(99_999).safeParse(QUANTITY_MAX_HARD);
  assert.equal(result.success, true);

  const over = quantitySchema(99_999).safeParse(QUANTITY_MAX_HARD + 1);
  assert.equal(over.success, false);
});

test("quantitySchema still allows the minimum when stock is 0 (pre-order)", () => {
  const result = quantitySchema(0).safeParse(QUANTITY_MIN);
  assert.equal(result.success, true);
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
