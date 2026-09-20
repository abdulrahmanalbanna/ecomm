import Image from "next/image";
import { getLocale, getTranslations } from "next-intl/server";
import type { CSSProperties } from "react";

import { brands, pickLocale, projects } from "../catalog";
import { SectionHead } from "./SectionHead";
import { IconArrow } from "./Icons";

/**
 * Static home sections, rendered as **Server Components**.
 *
 * These three sections (`ProjectsGrid`, `BrandsMarquee`, `CtaBand`) contain no
 * client state at all — they render from static catalog data plus
 * `next-intl` messages. Previously they lived inside the `"use client"`
 * `HomeSections.tsx` monolith purely so they could attach the scroll-reveal
 * observer; that shipped their JSX and helpers to the browser for no benefit.
 *
 * The reveal animation is preserved by wrapping them once in
 * [`RevealScope`](./RevealScope.tsx) (the only client boundary here), whose
 * IntersectionObserver scans the whole subtree. `.reveal` elements therefore
 * still start hidden and get `.in` on scroll exactly as before.
 *
 * Copy is resolved with `getTranslations`/`getLocale` on the server, so these
 * sections are fully localized in the prerendered HTML and need no
 * `NextIntlClientProvider` round-trip.
 */

/* ============ projects grid ============ */
export async function ProjectsGrid() {
  const t = await getTranslations("home.projects");
  return (
    <section id="projects" className="scroll-mt-32 py-14">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <SectionHead
          kicker={t("kicker")}
          title={t("title")}
          desc={t("desc")}
        />
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          {projects.map((pr, i) => (
            <a
              key={pr.id}
              href="#why-us"
              style={{ "--rv-delay": `${(i % 3) * 80}ms` } as CSSProperties}
              className="reveal group relative block h-44 overflow-hidden rounded-xl text-right md:h-56"
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
            </a>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ============ brands marquee ============ */
export async function BrandsMarquee() {
  const t = await getTranslations("home.brands");
  const locale = await getLocale();
  const loop = [...brands, ...brands];
  return (
    <section className="border-t border-muted-200/60 py-12">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <SectionHead
          kicker={t("kicker")}
          title={t("title")}
          action={t("all")}
          actionHref="#why-us"
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

/* ============ CTA band ============ */
export async function CtaBand() {
  const t = await getTranslations("home.cta");
  return (
    <section className="border-y border-muted-200/60 bg-secondary-100/60 py-12">
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
