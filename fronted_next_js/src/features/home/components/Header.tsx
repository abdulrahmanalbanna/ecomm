"use client";

import Image from "next/image";

import { useEffect, useRef, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { categories as staticCategories, formatPrice, pickLocale, type Category, type Product } from "../catalog";
import { useShopSettings } from "../use-settings";
import type { CatalogState } from "../use-catalog";
import { branding } from "@/config/branding";
import { useCart } from "@/stores/cart";
import { useMounted } from "@/hooks/use-home";
import { IconCart, IconChevron, IconPhone, IconSearch, IconUser, IconWhatsApp } from "@/features/home/components/Icons";

export function Logo({ light = false }: { light?: boolean }) {
  const t = useTranslations("home");
  const locale = useLocale();
  const storeName = locale === "ar" ? branding.name.ar : branding.name.en;
  const logoSrc = light ? branding.logos.footer : branding.logos.header;
  return (
    <a href="#top" className="flex items-center gap-2.5" aria-label={`${storeName} — Home`}>
      <Image
        src={logoSrc}
        alt={storeName}
        width={160}
        height={50}
        priority
        className="h-12 w-auto rounded-lg object-contain"
      />
    </a>
  );
}

function Ticker() {
  const locale = useLocale();
  const { settings } = useShopSettings();
  const items = [...settings.ticker_items, ...settings.ticker_items];
  return (
    <div className="overflow-hidden bg-primary-950 py-1.5" dir="ltr" suppressHydrationWarning>
      <div className="anim-ticker flex w-max items-center gap-8">
        {items.map((item, i) => (
          <span key={i} dir="rtl" className="flex items-center gap-8 whitespace-nowrap text-[12px] font-bold text-secondary-300">
            <span className="flex items-center gap-2">
              <span className="h-1 w-1 rounded-full bg-secondary-500" />
              {item}
            </span>
          </span>
        ))}
      </div>
    </div>
  );
}

function SearchBox({
  onFocusSearch,
  inputId = "site-search",
  catalog,
}: {
  onFocusSearch?: () => void;
  inputId?: string;
  catalog: CatalogState;
}) {
  const t = useTranslations("home");
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);

  const products = catalog.products;
  const byId = catalog.byId;
  const term = q.trim().toLowerCase();
  const results =
    term.length >= 2
      ? products
          .filter(
            (p) =>
              p.name.toLowerCase().includes(term) ||
              p.spec.toLowerCase().includes(term),
          )
          .slice(0, 6)
      : [];

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, []);

  // Build a category id -> name lookup using the live list first, then
  // the static fallback. This is a safe Map used purely for label display.
  const categoryName = (id: string) => {
    const live = catalog.categories.find((c: Category) => c.id === id);
    if (live) return live.name;
    const fallback = staticCategories.find((c) => c.id === id);
    return fallback?.name ?? "";
  };

  return (
    <div ref={boxRef} className="relative w-full">
      <div className="flex h-11 items-center overflow-hidden rounded-xl border border-muted-200 bg-surface transition-all duration-300 focus-within:border-secondary-500 focus-within:shadow-soft">
        <input
          id={inputId}
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setOpen(true);
            onFocusSearch?.();
          }}
          onFocus={() => setOpen(true)}
          placeholder={t("searchPlaceholder")}
          className="h-full w-full bg-transparent px-4 text-[13.5px] font-medium outline-none placeholder:text-muted-300"
        />
        <button
          onClick={() => setOpen(true)}
          className="grid h-full w-12 shrink-0 place-items-center bg-primary-800 text-secondary-300 transition-colors hover:bg-secondary-500 hover:text-primary-950"
          aria-label={t("search")}
        >
          <IconSearch size={18} />
        </button>
      </div>

      {open && term.length >= 2 && (
        <div className="anim-rise absolute inset-x-0 top-[calc(100%+8px)] z-50 overflow-hidden rounded-xl border border-muted-200 bg-surface shadow-lift">
          {results.length === 0 ? (
            <p className="px-4 py-5 text-center text-[13px] font-bold text-muted-400">{t("noResults", { query: q })}</p>
          ) : (
            results.map((p: Product) => (
              <a
                key={p.id}
                href={`#rail-${p.category}`}
                onClick={() => setOpen(false)}
                className="flex items-center gap-3 border-b border-muted-200/50 px-3 py-2 transition-colors last:border-0 hover:bg-primary-50"
              >
                <Image src={p.image} alt="" width={44} height={44} className="h-11 w-11 rounded-lg object-cover" />
                <span className="flex-1">
                  <span className="block truncate text-[13px] font-bold text-muted-900">{p.name}</span>
                  <span className="text-[11.5px] text-muted-400">{categoryName(p.category)}</span>
                </span>
                <span className="font-display text-sm font-extrabold text-primary-800 tabular">
                  {formatPrice(p.price)} <span className="text-[10px] text-muted-400">SAR</span>
                </span>
              </a>
            ))
          )}
        </div>
      )}
    </div>
  );
}

export function Header({ catalog }: { catalog: CatalogState }) {
  const t = useTranslations("home");
  const { count, badgeKey, setDrawerOpen } = useCart();
  const [scrolled, setScrolled] = useState(false);
  const [activeCat, setActiveCat] = useState<string | null>(null);
  const mounted = useMounted();

  const categories = catalog.categories.length > 0 ? catalog.categories : staticCategories;

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 40);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const goTo = (id: string) => {
    document.getElementById(`rail-${id}`)?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  return (
    <header className="sticky top-0 z-40">
      <Ticker />

      <div
        className={`border-b border-muted-200/70 bg-background/95 backdrop-blur transition-shadow duration-300 ${
          scrolled ? "shadow-soft" : ""
        }`}
      >
        <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 md:gap-6 lg:px-8">
          <Logo />

          <div className="hidden flex-1 md:block">
            <SearchBox inputId="site-search-desktop" catalog={catalog} />
          </div>

          <div className="mr-auto flex items-center gap-1.5 md:mr-0 md:gap-2">
            <a
              href="tel:920000000"
              className="hidden items-center gap-2 rounded-xl px-3 py-2 transition-colors hover:bg-primary-50 lg:flex"
            >
              <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary-800 text-secondary-300">
                <IconPhone size={17} />
              </span>
              <span className="leading-tight">
                <span className="block text-[10.5px] font-bold text-muted-400">{t("contactUs")}</span>
                <span className="block text-[13px] font-extrabold text-primary-900 tabular" dir="ltr">776 341 682</span>
              </span>
            </a>

            <a
              href="https://wa.me/966500000000"
              target="_blank"
              rel="noreferrer"
              className="hidden items-center gap-2 rounded-xl px-3 py-2 transition-colors hover:bg-primary-50 lg:flex"
            >
              <span className="grid h-9 w-9 place-items-center rounded-lg bg-success text-white">
                <IconWhatsApp size={17} />
              </span>
              <span className="leading-tight">
                <span className="block text-[10.5px] font-bold text-muted-400">{t("whatsapp")}</span>
                <span className="block text-[13px] font-extrabold text-primary-900">{t("instantConsultation")}</span>
              </span>
            </a>

            <button
              onClick={() => toastFn?.(t("loginSoon"))}
              className="relative flex h-10 items-center gap-2 rounded-xl border border-muted-200 bg-surface p-2 text-[13px] font-extrabold text-primary-900 transition-colors hover:border-secondary-500"
            >
              <IconUser size={25} className="text-primary-950" />
            </button>

            <button
              onClick={() => setDrawerOpen(true)}
              className="relative flex h-10 items-center gap-2 rounded-xl border border-muted-200 bg-surface p-2 text-[13.5px] font-extrabold text-primary-950 transition-all duration-300 hover:border-secondary-500 hover:bg-surface active:scale-95"
              aria-label={mounted ? t("cartCount", { count }) : t("cartCount", { count: 0 })}
            >
              <IconCart size={25} />
              {mounted && count > 0 && (
                <span
                  key={badgeKey}
                  className="anim-badge-pop absolute -left-1.5 -top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-primary-900 px-1 text-[10.5px] font-black text-secondary-300 tabular"
                >
                  {count}
                </span>
              )}
            </button>
          </div>
        </div>

        {/* mobile search */}
        <div className="px-4 pb-3 md:hidden">
          <SearchBox catalog={catalog} />
        </div>

        {/* categories nav */}
        <nav className="hidden border-t border-muted-200/60 md:block" aria-label={t("categories")}>
          <div className="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto no-scrollbar px-4 lg:px-8">
            <button
              onClick={() => document.getElementById("categories")?.scrollIntoView({ behavior: "smooth" })}
              className="flex items-center gap-1.5 whitespace-nowrap border-b-2 border-secondary-500 px-3.5 py-2.5 text-[13px] font-extrabold text-primary-900"
            >
              {t("allCategories")}
              <IconChevron size={13} className="rotate-90" />
            </button>
            {categories.map((c: Category) => (
              <button
                key={c.id}
                onClick={() => {
                  setActiveCat(c.id);
                  goTo(c.id);
                }}
                className={`whitespace-nowrap border-b-2 px-3.5 py-2.5 text-[13px] font-bold transition-colors ${
                  activeCat === c.id
                    ? "border-secondary-500 text-primary-900"
                    : "border-transparent text-muted-500 hover:text-primary-800"
                }`}
              >
                {c.name}
              </button>
            ))}
            <span className="mr-auto whitespace-nowrap py-2.5 pl-2 text-[12px] font-bold text-secondary-700">
              ✦ {t("installments")}
            </span>
          </div>
        </nav>
      </div>
    </header>
  );
}

let toastFn: ((m: string) => void) | null = null;
export function bindToastFn(fn: (m: string) => void) {
  toastFn = fn;
}
