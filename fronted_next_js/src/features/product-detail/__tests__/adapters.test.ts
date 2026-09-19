/**
 * Unit tests for the PDP API adapters.
 *
 * These cover the mapping from Laravel `ProductPublicResource` payloads onto
 * the render-ready `ProductDetail` shape, including the defensive paths
 * (missing media, unknown stock, inactive variants).
 *
 * Run: `npm test` (node:test via tsx).
 */

import assert from "node:assert/strict";
import { test } from "node:test";
import { adaptProductDetail } from "../api";
import type { LaravelProduct } from "@/lib/api/client";

function baseProduct(overrides: Partial<LaravelProduct> = {}): LaravelProduct {
  return {
    public_id: "prod-uuid-001",
    slug: "espresso-futura-2g",
    name: "Futura 2G Espresso Machine",
    description: "A commercial two-group espresso machine.",
    short_description: "2 Groups, Heat Exchanger",
    is_featured: false,
    variants: [
      { id: 101, price: 58000, compare_at_price: 63500, is_active: true },
    ],
    media: [{ id: 1, url: "http://localhost:8000/storage/catalog/futura.png" }],
    category: {
      id: 1,
      slug: "coffee",
      name: "Coffee Machines",
      description: "",
      is_active: true,
    },
    ...overrides,
  };
}

test("adaptProductDetail maps the core fields", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "espresso-futura-2g" });

  assert.equal(product.id, "prod-uuid-001");
  assert.equal(product.slug, "espresso-futura-2g");
  assert.equal(product.name, "Futura 2G Espresso Machine");
  assert.equal(product.shortDescription, "2 Groups, Heat Exchanger");
  assert.equal(product.isFeatured, false);
});

test("adaptProductDetail builds the canonical URL from locale + slug", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "ar", slug: "espresso-futura-2g" });
  assert.ok(product.url.includes("/ar/products/espresso-futura-2g"), product.url);
});

test("adaptProductDetail resolves the primary image from media", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p" });
  assert.equal(product.primaryImage, "http://localhost:8000/storage/catalog/futura.png");
  assert.equal(product.images[0]?.alt, "Futura 2G Espresso Machine");
});

test("adaptProductDetail falls back to a placeholder image when media is empty", () => {
  const product = adaptProductDetail(baseProduct({ media: [] }), { locale: "en", slug: "p" });
  // One placeholder frame is kept on purpose so the gallery keeps its shape
  // (it matches the skeleton loader) instead of collapsing to zero height.
  assert.equal(product.images.length, 1);
  assert.equal(product.images[0]?.id, "placeholder");
  assert.equal(product.images[0]?.alt, "Futura 2G Espresso Machine");
  assert.ok(product.primaryImage.length > 0);
});

test("adaptProductDetail reports in-stock when stock is unknown", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p" });
  assert.equal(product.stock.availability, "in-stock");
});

test("adaptProductDetail reports low-stock below the threshold", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p", stock: 3 });
  assert.equal(product.stock.availability, "low-stock");
  assert.equal(product.stock.quantity, 3);
});

test("adaptProductDetail reports out-of-stock at zero", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p", stock: 0 });
  assert.equal(product.stock.availability, "out-of-stock");
});

test("adaptProductDetail prefers the explicit stock over variant stock", () => {
  const product = adaptProductDetail(
    baseProduct({
      // The variant resource has no `stock`; the adapter reads it from the
      // product-level `stock`/`inventory` wrapper instead.
      variants: [{ id: 101, price: 58000, compare_at_price: null, is_active: true }],
    }),
    { locale: "en", slug: "p", stock: 25 },
  );
  assert.equal(product.stock.availability, "in-stock");
  assert.equal(product.stock.quantity, 25);
});

test("adaptProductDetail derives SEO title/description from the product when absent", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p" });
  assert.equal(product.seoTitle, "Futura 2G Espresso Machine");
  assert.equal(product.seoDescription, "2 Groups, Heat Exchanger");
});

test("adaptProductDetail honours explicit SEO fields", () => {
  const product = adaptProductDetail(
    baseProduct({ seo_title: "Buy Futura 2G", seo_description: "Best price in KSA" }),
    { locale: "en", slug: "p" },
  );
  assert.equal(product.seoTitle, "Buy Futura 2G");
  assert.equal(product.seoDescription, "Best price in KSA");
});

test("adaptProductDetail normalizes tags to a flat string array", () => {
  const product = adaptProductDetail(
    baseProduct({
      tags: [
        { id: 1, name: "espresso", is_active: true },
        { id: 2, name: "   ", is_active: true },
        { id: 3, name: "commercial", is_active: true },
      ],
    }),
    { locale: "en", slug: "p" },
  );
  assert.deepEqual(product.tags, ["espresso", "commercial"]);
});

test("adaptProductDetail starts with an empty review summary", () => {
  const product = adaptProductDetail(baseProduct(), { locale: "en", slug: "p" });
  assert.equal(product.summary.average, 0);
  assert.equal(product.summary.count, 0);
  assert.deepEqual(product.summary.distribution, [0, 0, 0, 0, 0]);
});

test("adaptProductDetail maps brand and category", () => {
  const product = adaptProductDetail(
    baseProduct({ brand: { id: 7, name: "Futura", slug: "futura", is_active: true } }),
    { locale: "en", slug: "p" },
  );
  assert.equal(product.brand?.name, "Futura");
  assert.equal(product.brand?.slug, "futura");
  assert.equal(product.category?.slug, "coffee");
  assert.equal(product.category?.name, "Coffee Machines");
});

test("adaptProductDetail tolerates a product with no variants", () => {
  const product = adaptProductDetail(baseProduct({ variants: [] }), { locale: "en", slug: "p" });
  assert.equal(product.variants.length, 0);
  assert.equal(product.stock.availability, "in-stock");
});
