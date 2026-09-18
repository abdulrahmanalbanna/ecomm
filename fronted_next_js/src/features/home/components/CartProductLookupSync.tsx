"use client";

import { useEffect } from "react";
import { setCartProductLookup } from "@/stores/cart";
import type { Product } from "../catalog";

export function CartProductLookupSync({ byId }: { byId: Map<string, Product> }) {
  useEffect(() => {
    setCartProductLookup(byId);
  }, [byId]);

  return null;
}
