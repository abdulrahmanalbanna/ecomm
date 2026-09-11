import { Header } from "./Header";
import { Hero } from "./Hero";
import { BrandsMarquee, CategoryTiles, CtaBand, ProductRail, ProjectsGrid, TabsSection, WhyUs } from "./HomeSections";
import { CartDrawer, ChatWidget, Footer, MobileNav, Toasts } from "./Chrome";
import { categories } from "../catalog";

export function HomePage() {
  return <div className="grain min-h-screen">
    <Header />
    <main>
      <Hero />
      <CategoryTiles />
      {categories.map((c) => <ProductRail key={c.id} catId={c.id} />)}
      <ProjectsGrid /><TabsSection /><BrandsMarquee /><WhyUs /><CtaBand />
    </main>
    <Footer /><CartDrawer /><ChatWidget /><MobileNav /><Toasts />
  </div>;
}
