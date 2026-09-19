/**
 * Unit tests for the PDP variant selector logic.
 *
 * Run: `npm test` (node:test via tsx).
 */

import assert from "node:assert/strict";
import { test } from "node:test";
import { isSelectionComplete, resolveVariant } from "../components/VariantSelector";
import type { ProductAttribute, ProductVariant } from "../types";

const variants: ProductVariant[] = [
  { id: 1, sku: "TSH-RED-M", name: "Red M", price: 100, compareAtPrice: null, weightGrams: 200, dimensions: null, isActive: true, attributes: { color: "Red", size: "M" } },
  { id: 2, sku: "TSH-RED-L", name: "Red L", price: 110, compareAtPrice: null, weightGrams: 220, dimensions: null, isActive: true, attributes: { color: "Red", size: "L" } },
  { id: 3, sku: "TSH-BLU-M", name: "Blue M", price: 100, compareAtPrice: 130, weightGrams: 200, dimensions: null, isActive: true, attributes: { color: "Blue", size: "M" } },
  { id: 4, sku: "TSH-BLU-L", name: "Blue L", price: 110, compareAtPrice: null, weightGrams: 220, dimensions: null, isActive: false, attributes: { color: "Blue", size: "L" } },
];

const attributes: ProductAttribute[] = [
  { id: 1, name: "color", code: "color", options: [{ name: "Red", value: "Red" }, { name: "Blue", value: "Blue" }] },
  { id: 2, name: "size", code: "size", options: [{ name: "M", value: "M" }, { name: "L", value: "L" }] },
];

test("resolveVariant returns null when nothing is selected", () => {
  assert.equal(resolveVariant(variants, {}), null);
});

test("resolveVariant matches a complete selection", () => {
  const resolved = resolveVariant(variants, { color: "Red", size: "L" });
  assert.equal(resolved?.id, 2);
  assert.equal(resolved?.price, 110);
});

test("resolveVariant matches a partial selection when only one variant fits", () => {
  const resolved = resolveVariant(variants, { color: "Red" });
  assert.equal(resolved?.id, 1);
});

test("resolveVariant returns null for a combination that does not exist", () => {
  assert.equal(resolveVariant(variants, { color: "Green", size: "M" }), null);
});

test("resolveVariant is order-independent across attribute groups", () => {
  assert.equal(resolveVariant(variants, { size: "M", color: "Blue" })?.id, 3);
});

test("isSelectionComplete is false for an empty selection", () => {
  assert.equal(isSelectionComplete(attributes, {}), false);
});

test("isSelectionComplete is false for a partial selection", () => {
  assert.equal(isSelectionComplete(attributes, { color: "Red" }), false);
});

test("isSelectionComplete is true once every group has a value", () => {
  assert.equal(isSelectionComplete(attributes, { color: "Red", size: "M" }), true);
});

test("isSelectionComplete is true when there are no attribute groups", () => {
  assert.equal(isSelectionComplete([], {}), true);
});
