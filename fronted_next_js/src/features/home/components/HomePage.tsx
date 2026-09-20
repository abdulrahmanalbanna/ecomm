import type { ReactNode } from "react";
import type { ShopSettings } from "../api";
import type { Category, Product } from "../catalog";
import { Header } from "./Header";
import { Hero } from "./Hero";
import { CategoryTiles, ProductRail, TabsSection, WhyUs } from "./HomeSections";
import { CartDrawer, ChatWidget, Footer, MobileNav, Toasts } from "./Chrome";
import { CartProductLookupSync } from "./CartProductLookupSync";

export type HomePageProps = {
  settings?: ShopSettings | null;
  categories?: Category[];
  products?: Product[];
  byId?: Map<string, Product>;
  byCategory?: Map<string, Product[]>;
  /**
   * Static, non-interactive sections (`ProjectsGrid`, `BrandsMarquee`,
   * `CtaBand`) are rendered as Server Components by the route and passed in
   * here. They are wrapped in a single `RevealScope` client boundary at the
   * call site so their (large) JSX never reaches the client bundle.
   */
  staticSections?: ReactNode;
};

export function HomePage({
  settings = null,
  categories = [],
  products = [],
  byId = new Map(),
  byCategory = new Map(),
  staticSections = null,
}: HomePageProps) {
  const catalogState = {
    categories,
    products,
    byId,
    byCategory,
    live: categories.length > 0 || products.length > 0,
    loading: false,
    error: null,
  };

  return (
    <div className="grain min-h-screen">
      <CartProductLookupSync byId={byId} />
      <Header catalog={catalogState} settings={settings} />
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
        {staticSections}
        <TabsSection byId={byId} />
        <WhyUs settings={settings} />
      </main>
      <Footer categories={categories} settings={settings} />
      <CartDrawer byId={byId} settings={settings} />
      <ChatWidget />
      <MobileNav />
      <Toasts />
    </div>
  );
}
