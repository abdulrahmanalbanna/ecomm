"use client";

import { useEffect } from "react";
import { Header } from "./Header";
import { Hero } from "./Hero";
import { BrandsMarquee, CategoryTiles, CtaBand, ProductRail, ProjectsGrid, TabsSection, WhyUs } from "./HomeSections";
import { CartDrawer, ChatWidget, Footer, MobileNav, Toasts } from "./Chrome";
import { useCatalog } from "@/features/home/use-catalog";
import { setCartProductLookup } from "@/stores/cart";

export function HomePage() {
  const catalog = useCatalog();
  const { categories, byCategory, byId } = catalog;

  // Keep the cart store's product lookup in sync with the live catalog
  // snapshot. The cart store uses this to resolve cart-line ids (now
  // Laravel `public_id` UUIDs) to display names and prices; it falls
  // back to the static catalog for unknown / pre-migration ids.
  useEffect(() => {
    setCartProductLookup(byId);
    return () => {
      // Don't clear on unmount during a normal page nav — leaving the
      // lookup installed keeps the cart store consistent across pages.
    };
  }, [byId]);

  return (
    <div className="grain min-h-screen">
      <Header catalog={catalog} />
      <main>
        <Hero categories={categories} />
        <CategoryTiles categories={categories} />
        {categories.map((c) => (
          <ProductRail
            key={c.id}
            catId={c.id}
            items={byCategory.get(c.id) ?? []}
          />
        ))}
        <ProjectsGrid />
        <TabsSection byId={byId} />
        <BrandsMarquee />
        <WhyUs />
        <CtaBand />
      </main>
      <Footer categories={categories} />
      <CartDrawer byId={byId} />
      <ChatWidget />
      <MobileNav />
      <Toasts />
    </div>
  );
}
