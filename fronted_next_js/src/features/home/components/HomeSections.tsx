"use client";

import Image from "next/image";

import { Fragment, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { useLocale, useTranslations } from "next-intl";
import {
  brands,
  categories as staticCategories,
  formatPrice,
  IMG,
  pickLocale,
  products as staticProducts,
  projects,
  type Category,
  type Product,
} from "../catalog";
import { useShopSettings } from "../use-settings";
import { useCountUp, useInView, useRevealObserver } from "@/hooks/use-home";
import { AddButton, ProductCard, PromoTile, Stars } from "@/features/home/components/ProductCard";
import {
  categoryIcon,
  IconArrow,
  IconCargo,
  IconChevron,
  IconCompass,
  IconShield,
  IconTruck,
  IconWrench,
} from "@/features/home/components/Icons";

const featureIcons: Record<string, ReactNode> = {
  cargo: <IconCargo size={26} />,
  shield: <IconShield size={26} />,
  wrench: <IconWrench size={26} />,
  compass: <IconCompass size={26} />,
};

/* ============ section heading ============ */
function SectionHead({
  kicker,
  title,
  desc,
  action,
  onAction,
  dark = false,
}: {
  kicker: string;
  title: string;
  desc?: string;
  action?: string;
  onAction?: () => void;
  dark?: boolean;
}) {
  return (
    <div className="reveal mb-7 flex flex-wrap items-end justify-between gap-4">
      <div>
        <p className={`mb-1.5 flex items-center gap-2 text-[12.5px] font-extrabold ${dark ? "text-secondary-400" : "text-secondary-600"}`}>
          <span className={`h-[2px] w-7 rounded-full ${dark ? "bg-secondary-400" : "bg-secondary-500"}`} />
          {kicker}
        </p>
        <h2 className={`font-display text-[26px] font-black leading-tight sm:text-[32px] ${dark ? "text-background" : "text-muted-900"}`}>
          {title}
        </h2>
        {desc && <p className={`mt-2 max-w-2xl text-[14px] font-medium leading-7 ${dark ? "text-primary-100/75" : "text-muted-500"}`}>{desc}</p>}
      </div>
      {action && (
        <button
          onClick={onAction}
          className={`group flex items-center gap-2 rounded-xl border px-4 py-2.5 text-[13px] font-extrabold transition-all duration-300 active:scale-95 ${
            dark
              ? "border-primary-600 text-secondary-300 hover:border-secondary-500 hover:bg-primary-800"
              : "border-muted-200 bg-surface text-primary-900 hover:border-secondary-500 hover:text-secondary-700"
          }`}
        >
          {action}
          <IconArrow size={16} className="transition-transform duration-300 group-hover:-translate-x-1" />
        </button>
      )}
    </div>
  );
}

/* ============ 1. category tiles ============ */
export function CategoryTiles({
  categories,
}: {
  categories: Category[];
}) {
  const t = useTranslations("home.categoriesSection");
  const ref = useRevealObserver<HTMLDivElement>();
  const railRef = useRef<HTMLDivElement>(null);
  const displayCategories = categories.length > 0 ? categories : staticCategories;
  const scroll = (dir: 1 | -1) =>
    railRef.current?.scrollBy({ left: dir * 320 * (document.documentElement.dir === "rtl" ? 1 : -1), behavior: "smooth" });

  return (
    <section id="categories" className="mx-auto max-w-7xl px-4 pt-14 lg:px-8" ref={ref}>
      <SectionHead
        kicker={t("kicker")}
        title={t("title")}
        desc={t("desc")}
        action={t("viewAll")}
        onAction={() => document.getElementById("rail-coffee")?.scrollIntoView({ behavior: "smooth" })}
      />

      <div className="reveal relative">
        <div ref={railRef} className="flex gap-4 overflow-x-auto no-scrollbar pb-2 snap-x" style={{ scrollSnapType: "x proximity" }}>
          {displayCategories.map((c: Category, i: number) => (
            <button
              key={c.id}
              style={{ "--rv-delay": `${i * 60}ms` } as CSSProperties}
              onClick={() => document.getElementById(`rail-${c.id}`)?.scrollIntoView({ behavior: "smooth", block: "start" })}
              className="group relative w-56 shrink-0 snap-start overflow-hidden rounded-xl bg-primary-900 text-right transition-all duration-300 hover:-translate-y-1.5 hover:shadow-lift md:w-64"
            >
              <Image
                src={c.image}
                alt={c.name}
                width={512}
                height={400}
                className="h-40 w-full object-cover opacity-80 transition-all duration-700 group-hover:scale-110 group-hover:opacity-100 md:h-44"
              />
              <div className="absolute inset-x-0 top-0 h-full bg-gradient-to-t from-primary-950 via-primary-950/35 to-transparent" />
              <div className="absolute inset-x-0 bottom-0 p-4">
                <h3 className="font-display text-[16px] font-extrabold text-background">{c.name}</h3>
                <p className="mt-0.5 line-clamp-1 text-[11.5px] font-medium text-primary-100/70">{c.desc}</p>
                <span className="mt-2 inline-flex items-center gap-1 text-[11.5px] font-extrabold text-secondary-400 opacity-0 transition-all duration-300 group-hover:opacity-100">
                  {t("browse")}
                  <IconArrow size={13} />
                </span>
              </div>
            </button>
          ))}
        </div>

        <div className="absolute -top-14 left-0 hidden gap-2 md:flex">
          <button onClick={() => scroll(1)} aria-label={t("previous")} className="grid h-10 w-10 place-items-center rounded-xl border border-muted-200 bg-surface text-primary-900 transition-all hover:border-secondary-500 hover:text-secondary-600 active:scale-90">
            <IconChevron size={17} />
          </button>
          <button onClick={() => scroll(-1)} aria-label={t("next")} className="grid h-10 w-10 place-items-center rounded-xl border border-muted-200 bg-surface text-primary-900 transition-all hover:border-secondary-500 hover:text-secondary-600 active:scale-90">
            <IconChevron size={17} className="rotate-180" />
          </button>
        </div>
      </div>
    </section>
  );
}

/* ============ 2. product rail per category ============ */
const promoCopy: Record<string, { title: string; sub: string }> = {
  coffee: { title: "coffee.title", sub: "coffee.sub" },
  grinders: { title: "grinders.title", sub: "grinders.sub" },
  cooling: { title: "cooling.title", sub: "cooling.sub" },
  cooking: { title: "cooking.title", sub: "cooking.sub" },
  frying: { title: "frying.title", sub: "frying.sub" },
  bakery: { title: "bakery.title", sub: "bakery.sub" },
  drinks: { title: "drinks.title", sub: "drinks.sub" },
};

export function ProductRail({
  catId,
  items,
}: {
  catId: string;
  items: Product[];
}) {
  const t = useTranslations("home.categoriesSection");
  const promoT = useTranslations("home.promo");
  const cat = staticCategories.find((c) => c.id === catId) ?? staticCategories[0];
  const ref = useRevealObserver<HTMLElement>();
  const railRef = useRef<HTMLDivElement>(null);
  const scroll = (dir: 1 | -1) =>
    railRef.current?.scrollBy({ left: dir * 600 * (document.documentElement.dir === "rtl" ? 1 : -1), behavior: "smooth" });

  const goToProjects = () => document.getElementById("projects")?.scrollIntoView({ behavior: "smooth" });

  // If the live catalog did not return items for this slug (e.g. unknown
  // backend slug), fall back to the static list so the rail still renders.
  const displayItems = items.length > 0 ? items : staticProducts.filter((p) => p.category === catId);

  return (
    <section id={`rail-${catId}`} ref={ref} className="scroll-mt-36 border-t border-muted-200/60 py-10">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <div className="reveal mb-6 flex items-center gap-3.5">
          <span className="grid place-items-center rounded-xl text-primary-950 shadow-soft">
            {categoryIcon(catId, 27)}
          </span>
          <div className="flex-1">
            <h2 className="font-display text-[22px] font-black text-muted-900 sm:text-[24px]">{cat?.name ?? catId}</h2>
            <p className="text-[12.5px] font-medium text-muted-400">{cat?.desc ?? ""} — {t("products", { count: displayItems.length })}</p>
          </div>
          <div className="hidden gap-2 sm:flex">
            <button onClick={() => scroll(1)} aria-label={t("previous")} className="grid h-10 w-10 place-items-center rounded-xl border border-muted-200 bg-surface text-primary-900 transition-all hover:border-secondary-500 hover:text-secondary-600 active:scale-90">
              <IconChevron size={17} />
            </button>
            <button onClick={() => scroll(-1)} aria-label={t("next")} className="grid h-10 w-10 place-items-center rounded-xl border border-muted-200 bg-surface text-primary-900 transition-all hover:border-secondary-500 hover:text-secondary-600 active:scale-90">
              <IconChevron size={17} className="rotate-180" />
            </button>
          </div>
        </div>

        <div
          ref={railRef}
          className="reveal flex gap-4 overflow-x-auto no-scrollbar pb-2 snap-x"
          style={{ scrollSnapType: "x proximity", ["--rv-delay" as string]: "90ms" }}
        >
          {displayItems.map((p: Product, i: number) => (
            <Fragment key={p.id}>
              <div className="w-60 shrink-0 snap-start md:w-64">
                <ProductCard p={p} />
              </div>
              {i === 3 && (
                <div className="shrink-0 snap-start">
                  <PromoTile
                    title={promoT(promoCopy[catId]?.title ?? "coffee.title")}
                    sub={promoT(promoCopy[catId]?.sub ?? "coffee.sub")}
                    cta={promoT("orderPackage")}
                    image={catId === "bakery" || catId === "cooking" ? IMG.banner : cat?.image ?? IMG.hero}
                    onCta={goToProjects}
                  />
                </div>
              )}
            </Fragment>
          ))}
          {/* end cap */}
          <button
            onClick={() => document.getElementById("why-us")?.scrollIntoView({ behavior: "smooth" })}
            className="group flex w-48 shrink-0 snap-start flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-muted-200 text-center transition-all duration-300 hover:border-secondary-500 hover:bg-secondary-100/50"
          >
            <span className="grid h-12 w-12 place-items-center rounded-full bg-primary-800 text-secondary-300 transition-transform duration-300 group-hover:scale-110">
              <IconArrow size={20} />
            </span>
            <span className="px-4 font-display text-[14px] font-extrabold text-muted-700">
              {t("specialRequest")}
            </span>
          </button>
        </div>
      </div>
    </section>
  );
}

/* ============ 3. projects grid ============ */
export function ProjectsGrid() {
  const t = useTranslations("home.projects");
  const ref = useRevealObserver<HTMLElement>();
  return (
    <section id="projects" ref={ref} className="scroll-mt-32 py-14">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <SectionHead
          kicker={t("kicker")}
          title={t("title")}
          desc={t("desc")}
        />
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          {projects.map((pr, i) => (
            <button
              key={pr.id}
              style={{ "--rv-delay": `${(i % 3) * 80}ms` } as CSSProperties}
              onClick={() => document.getElementById("why-us")?.scrollIntoView({ behavior: "smooth" })}
              className="reveal group relative h-44 overflow-hidden rounded-xl text-right md:h-56"
            >
              <Image src={pr.image} alt={pr.name} fill sizes="(min-width: 1024px) 33vw, 50vw" className="object-cover transition-transform duration-700 group-hover:scale-110" />
              <div className="absolute inset-0 bg-gradient-to-t from-primary-950 via-primary-950/45 to-primary-950/10 transition-opacity duration-300 group-hover:via-primary-950/60" />
              <div className="absolute inset-x-0 bottom-0 p-4 md:p-5">
                <p className="text-[11px] font-bold text-secondary-300">{pr.tag}</p>
                <h3 className="mt-0.5 font-display text-[17px] font-extrabold text-background md:text-[20px]">{pr.name}</h3>
                <span className="mt-2 flex items-center gap-2 text-[12px] font-bold text-primary-100/80">
                  <span className="rounded-md bg-background/15 px-2 py-0.5 backdrop-blur-sm">{t("products", { count: pr.count })}</span>
                  <span className="flex translate-x-2 items-center gap-1 text-secondary-400 opacity-0 transition-all duration-300 group-hover:translate-x-0 group-hover:opacity-100">
                    {t("prepare")}
                    <IconArrow size={14} />
                  </span>
                </span>
              </div>
            </button>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ============ 4. tabs: new / bestsellers ============ */
export function TabsSection({
  byId,
}: {
  byId: Map<string, Product>;
}) {
  const t = useTranslations("home.weekly");
  const ref = useRevealObserver<HTMLElement>();
  const [tab, setTab] = useState<"new" | "best">("new");

  // Pull live items from the shared lookup; if the live catalog has not
  // loaded (or returned a sparse set) we fall back to the static list
  // so the section never renders empty.
  const liveList = Array.from(byId.values());
  const newItems = liveList.length > 0 ? liveList : staticProducts;
  // Cheap heuristic: "new arrivals" = first half, "best sellers" = second half.
  const half = Math.max(1, Math.floor(newItems.length / 2));
  const list = tab === "new" ? newItems.slice(0, half) : newItems.slice(half);

  return (
    <section ref={ref} className="border-t border-muted-200/60 bg-surface py-14">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <SectionHead
          kicker={t("kicker")}
          title={t("title")}
          desc={t("desc")}
        />

        <div className="reveal mb-7 flex w-fit rounded-xl border border-muted-200 bg-background p-1">
          {(
            [
              { id: "new", label: t("new") },
              { id: "best", label: t("best") },
            ] as const
          ).map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`rounded-lg px-5 py-2.5 text-[13.5px] font-extrabold transition-all duration-300 ${
                tab === t.id ? "bg-primary-900 text-secondary-300 shadow-soft" : "text-muted-500 hover:text-primary-900"
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>

        <div key={tab} className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
          {list.map((p: Product, i: number) => (
            <div key={`${tab}-${p.id}`} className="anim-rise" style={{ animationDelay: `${i * 55}ms` }}>
              <ProductCard p={p} />
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ============ 5. brands marquee ============ */
export function BrandsMarquee() {
  const t = useTranslations("home.brands");
  const locale = useLocale();
  const ref = useRevealObserver<HTMLElement>();
  const loop = [...brands, ...brands];
  return (
    <section ref={ref} className="border-t border-muted-200/60 py-12">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <SectionHead
          kicker={t("kicker")}
          title={t("title")}
          action={t("all")}
          onAction={() => document.getElementById("why-us")?.scrollIntoView({ behavior: "smooth" })}
        />
      </div>
      <div className="reveal marquee-paused overflow-hidden" dir="ltr">
        <div className="anim-marquee flex w-max items-center gap-4 px-4">
          {loop.map((b, i) => (
            <div
              key={i}
              dir="rtl"
              className="flex shrink-0 items-center gap-3 rounded-xl border border-muted-200 bg-surface px-6 py-3.5 transition-all duration-300 hover:-translate-y-1 hover:border-secondary-500 hover:shadow-soft"
            >
              <span className="grid h-10 w-10 place-items-center rounded-lg bg-primary-900 font-display text-[15px] font-black text-secondary-400">
                {b.en.slice(0, 1)}
              </span>
              <span>
                <span className="block font-display text-[15px] font-extrabold leading-5 text-muted-900" dir="ltr">
                  {b.en}
                </span>
                <span className="block text-[11.5px] font-bold text-muted-400">
                  {locale === "ar" ? b.ar : b.en} • {pickLocale(locale, b.since, b.sinceEn)}
                </span>
              </span>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ============ 6. why us + stats ============ */
function StatBlock({ value, suffix, label, delay }: { value: number; suffix: string; label: string; delay: number }) {
  const { ref, inView } = useInView<HTMLDivElement>(0.4);
  const v = useCountUp(value, inView);
  return (
    <div ref={ref} className="reveal rounded-xl bg-primary-800/70 p-5 ring-1 ring-primary-700" style={{ "--rv-delay": `${delay}ms` } as CSSProperties}>
      <p className="font-display text-[34px] font-black leading-none text-secondary-400 tabular">
        {v}
        <span className="text-[22px]">{suffix}</span>
      </p>
      <p className="mt-2 text-[13px] font-bold text-primary-100/80">{label}</p>
    </div>
  );
}

export function WhyUs({ settings: initialSettings }: { settings?: ReturnType<typeof useShopSettings>["settings"] | null } = {}) {
  const t = useTranslations("home.why");
  const ref = useRevealObserver<HTMLElement>();
  const { settings } = useShopSettings(initialSettings);
  const stats = settings.stats ?? [];
  const features = settings.features ?? [];
  return (
    <section id="why-us" ref={ref} className="relative scroll-mt-28 overflow-hidden bg-primary-900 py-16">
      <div className="pattern-dots absolute inset-0" aria-hidden />
      <div className="pattern-lines absolute inset-0" aria-hidden />

      <div className="relative mx-auto grid max-w-7xl gap-12 px-4 lg:grid-cols-[1fr_1.15fr] lg:px-8">
        {/* sticky intro + stats */}
        <div className="lg:sticky lg:top-40 lg:self-start">
          <SectionHead
            dark
            kicker={t("kicker")}
            title={t("title")}
            desc={t("desc")}
          />
          <div className="grid grid-cols-2 gap-4">
            {stats.map((s, i) => (
              <StatBlock key={s.label} value={s.value} suffix={s.suffix} label={s.label} delay={i * 90} />
            ))}
          </div>

          <div className="reveal mt-6 flex flex-wrap items-center gap-3 rounded-xl bg-primary-800/70 p-4 ring-1 ring-primary-700" style={{ "--rv-delay": "200ms" } as CSSProperties}>
            <span className="grid h-11 w-11 place-items-center rounded-xl bg-secondary-500 text-primary-950">
              <IconTruck size={22} />
            </span>
            <p className="flex-1 text-[13px] font-bold leading-6 text-primary-100">
              {t("coldShipping")}
              <span className="block text-[11.5px] font-medium text-primary-100/60">{t("tracking")}</span>
            </p>
            <span className="font-display text-lg font-black text-secondary-400">٤٨<span className="text-[12px]"> {t("hours")}</span></span>
          </div>
        </div>

        {/* feature list — staggered */}
        <div className="flex flex-col gap-5">
          {features.map((f, i) => (
            <div
              key={f.id}
              className={`reveal group flex gap-5 rounded-xl border border-primary-700 bg-primary-950/60 p-6 transition-all duration-300 hover:border-secondary-500/60 hover:bg-primary-800/80 ${
                i % 2 === 1 ? "lg:translate-x-10" : ""
              }`}
              style={{ "--rv-delay": `${i * 110}ms` } as CSSProperties}
            >
              <span className="grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-primary-800 text-secondary-400 ring-1 ring-primary-700 transition-all duration-300 group-hover:scale-110 group-hover:bg-secondary-500 group-hover:text-primary-950">
                {featureIcons[f.icon] ?? <IconShield size={26} />}
              </span>
              <div>
                <div className="flex items-baseline gap-3">
                  <h3 className="font-display text-[19px] font-extrabold text-background">{f.title}</h3>
                  <span className="font-display text-[13px] font-black text-secondary-500/50 tabular">0{i + 1}</span>
                </div>
                <p className="mt-1.5 text-[13.5px] font-medium leading-7 text-primary-100/75">{f.desc}</p>
              </div>
            </div>
          ))}

          {/* quote strip */}
          <figure className="reveal relative mt-2 overflow-hidden rounded-xl bg-secondary-500 p-6" style={{ "--rv-delay": "440ms" } as CSSProperties}>
            <svg className="absolute -left-3 -top-4 h-24 w-24 text-secondary-600/30" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
              <path d="M10 7H6a3 3 0 0 0-3 3v7h7v-7H7a3 3 0 0 1 3-3Zm11 0h-4a3 3 0 0 0-3 3v7h7v-7h-3a3 3 0 0 1 3-3Z" />
            </svg>
            <blockquote className="relative font-display text-[17px] font-extrabold leading-8 text-primary-950">
              {t("quote")}
            </blockquote>
            <figcaption className="relative mt-3 text-[12.5px] font-black text-primary-900/70">
              {t("quoteAuthor")} ★ ٥/٥
            </figcaption>
          </figure>
        </div>
      </div>
    </section>
  );
}

/* ============ 7. CTA band ============ */
export function CtaBand() {
  const t = useTranslations("home.cta");
  const ref = useRevealObserver<HTMLElement>();
  return (
    <section ref={ref} className="border-y border-muted-200/60 bg-secondary-100/60 py-12">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-4 lg:px-8">
        <div className="reveal">
          <h2 className="font-display text-[26px] font-black text-primary-900 sm:text-[30px]">{t("title")}</h2>
          <p className="mt-1.5 text-[14px] font-medium text-muted-500">
            {t("desc")}
          </p>
        </div>
        <div className="reveal flex flex-wrap gap-3" style={{ "--rv-delay": "120ms" } as CSSProperties}>
          <a
            href="https://wa.me/966500000000"
            target="_blank"
            rel="noreferrer"
            className="flex items-center gap-2 rounded-xl bg-primary-900 px-6 py-3.5 font-display text-[15px] font-extrabold text-secondary-300 transition-all duration-300 hover:bg-primary-800 active:scale-95"
          >
            {t("whatsapp")}
            <IconArrow size={17} />
          </a>
          <a
            href="tel:920000000"
            className="flex items-center gap-2 rounded-xl border-2 border-primary-900 px-6 py-3.5 font-display text-[15px] font-extrabold text-primary-900 transition-all duration-300 hover:bg-primary-900 hover:text-secondary-300 active:scale-95"
            dir="ltr"
          >
            920 012 345
          </a>
        </div>
      </div>
    </section>
  );
}

export { formatPrice };
