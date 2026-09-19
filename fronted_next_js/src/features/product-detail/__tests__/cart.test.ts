/**
 * Unit tests for the quantity-aware cart store behaviour.
 *
 * The store is a Zustand hook; in `node:test` we drive it through the plain
 * store API (`getState()` / `setState()`), which is exactly what the React
 * bindings subscribe to.
 *
 * Run: `npm test` (node:test via tsx).
 */

import assert from "node:assert/strict";
import { test } from "node:test";
import { selectCartItemCount, selectCartTotal, useCartStore } from "@/stores/cart";

const PRODUCT_ID = "prod-uuid-001";

function reset(): void {
  useCartStore.setState({ lines: [], toasts: [], badgeKey: 0, drawerOpen: false });
}

test("addQuantity creates a new line with the requested quantity", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 3, 10);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 3);
});

test("addQuantity adds to an existing line instead of replacing it", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  useCartStore.getState().addQuantity(PRODUCT_ID, 3, 10);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 5);
});

test("addQuantity clamps the combined total to the available stock", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 4, 5);
  useCartStore.getState().addQuantity(PRODUCT_ID, 4, 5);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 5);
});

test("addQuantity ignores a zero/negative quantity", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 0, 10);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 1);
});

test("addQuantity pushes a toast and bumps the badge key", () => {
  reset();
  const before = useCartStore.getState().badgeKey;
  useCartStore.getState().addQuantity(PRODUCT_ID, 1, 10, "Added to cart");
  const state = useCartStore.getState();
  assert.equal(state.badgeKey, before + 1);
  assert.equal(state.toasts.length, 1);
  assert.equal(state.toasts[0]?.msg, "Added to cart");
});

test("setQuantity overwrites the line quantity", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  useCartStore.getState().setQuantity(PRODUCT_ID, 7, 10);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 7);
});

test("setQuantity clamps to the hard cap when no max is given", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 1, 10);
  useCartStore.getState().setQuantity(PRODUCT_ID, 99_999);
  const line = useCartStore.getState().lines.find((l) => l.id === PRODUCT_ID);
  assert.equal(line?.qty, 99);
});

test("remove drops the line entirely", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  useCartStore.getState().remove(PRODUCT_ID);
  assert.equal(useCartStore.getState().lines.length, 0);
});

test("clear empties every line", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  useCartStore.getState().addQuantity("prod-uuid-002", 1, 10);
  useCartStore.getState().clear();
  assert.equal(useCartStore.getState().lines.length, 0);
});

test("selectCartItemCount sums units across lines", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  useCartStore.getState().addQuantity("prod-uuid-002", 3, 10);
  assert.equal(selectCartItemCount(useCartStore.getState()), 5);
});

test("selectCartTotal is zero for unknown products", () => {
  reset();
  useCartStore.getState().addQuantity(PRODUCT_ID, 2, 10);
  // No product lookup installed → unknown ids contribute nothing.
  assert.equal(selectCartTotal(useCartStore.getState()), 0);
});

test("setDrawerOpen toggles the drawer flag", () => {
  reset();
  useCartStore.getState().setDrawerOpen(true);
  assert.equal(useCartStore.getState().drawerOpen, true);
  useCartStore.getState().setDrawerOpen(false);
  assert.equal(useCartStore.getState().drawerOpen, false);
});
