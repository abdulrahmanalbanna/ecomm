import { adaptCategory, adaptProduct } from "../api";
import type { LaravelCategory, LaravelProduct } from "@/lib/api/client";
import assert from "node:assert/strict";
import { test } from "node:test";

test("adaptCategory transforms LaravelCategory correctly", () => {
  const rawCat: LaravelCategory = {
    id: 1,
    slug: "coffee",
    name: "Coffee Machines",
    description: "Espresso machines for cafes",
    is_active: true,
    image_url: "http://localhost:8000/storage/catalog/coffee.png",
  };

  const category = adaptCategory(rawCat);

  assert.equal(category.id, "coffee");
  assert.equal(category.name, "Coffee Machines");
  assert.equal(category.desc, "Espresso machines for cafes");
  assert.equal(category.image, "http://localhost:8000/storage/catalog/coffee.png");
});

test("adaptProduct transforms LaravelProduct with sellable variant correctly", () => {
  const rawProduct: LaravelProduct = {
    public_id: "prod-uuid-1234",
    slug: "espresso-futura-2g",
    name: "Futura 2G Espresso Machine",
    description: "Commercial espresso machine",
    short_description: "2 Groups, Heat Exchanger",
    is_featured: true,
    variants: [
      {
        id: 101,
        price: 58000,
        compare_at_price: 63500,
        is_active: true,
      },
    ],
    media: [
      {
        id: 1,
        url: "http://localhost:8000/storage/catalog/futura.png",
      },
    ],
    category: {
      id: 1,
      slug: "coffee",
      name: "Coffee",
      description: "",
      is_active: true,
    },
  };

  const product = adaptProduct(rawProduct);

  assert.equal(product.id, "prod-uuid-1234");
  assert.equal(product.name, "Futura 2G Espresso Machine");
  assert.equal(product.spec, "2 Groups, Heat Exchanger");
  assert.equal(product.price, 58000);
  assert.equal(product.oldPrice, 63500);
  assert.equal(product.category, "coffee");
  assert.equal(product.image, "http://localhost:8000/storage/catalog/futura.png");
  assert.equal(product.rating, 0);
  assert.equal(product.reviews, 0);
});
