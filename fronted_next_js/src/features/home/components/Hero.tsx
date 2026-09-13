"use client";

import Image from "next/image";

import { useTranslations, useLocale } from "next-intl";
import { useEffect, useState, type CSSProperties } from "react";
import { categories, cities, IMG, pickLocale } from "../catalog";
import { IconArrow, IconCheck, IconShield, IconTruck, IconWrench } from "@/features/home/components/Icons";

function RotatingStamp() {
  const t = useTranslations("home.hero");
  return (
    <div className="anim-spin-slow absolute -bottom-7 -left-7 z-20 hidden h-28 w-28 md:block" aria-hidden>
      <svg viewBox="0 0 120 120" className="h-full w-full drop-shadow-lg">
        <defs>
          <path id="stamp-circle" d="M60,60 m-42,0 a42,42 0 1,1 84,0 a42,42 0 1,1 -84,0" />
        </defs>
        <circle cx="60" cy="60" r="58" fill="#FF6A00" />
        <circle cx="60" cy="60" r="30" fill="#062B6F" />
        <text fontSize="12.5" fontWeight="800" fill="#062B6F" fontFamily="Cairo, sans-serif">
          <textPath href="#stamp-circle">
            {t("eyebrow")}
          </textPath>
        </text>
        <text x="60" y="66" textAnchor="middle" fontSize="17" fontWeight="900" fill="#FF6A00" fontFamily="Cairo, sans-serif">
          TG
        </text>
      </svg>
    </div>
  );
}



export function Hero() {
  const t = useTranslations("home.hero");
  const locale = useLocale();
  const [shipIndex, setShipIndex] = useState(0);
  const shipTo = pickLocale(locale, cities[shipIndex].ar, cities[shipIndex].en);
  const trustChips = [
    { icon: <IconShield size={15} />, label: t("trustOriginal") },
    { icon: <IconTruck size={15} />, label: t("trustShipping") },
    { icon: <IconWrench size={15} />, label: t("trustService") },
  ];
  useEffect(() => {
    const t = window.setInterval(() => {
      setShipIndex((i) => (i + 1) % cities.length);
    }, 2400);
    return () => window.clearInterval(t);
  }, []);

  const scrollTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth" });

  return (
    <section id="top" className="relative overflow-hidden bg-primary-900">
      <div className="pattern-dots absolute inset-0" aria-hidden />
      <div
        className="absolute -top-32 left-1/4 h-96 w-96 rounded-full bg-secondary-500/10 blur-3xl"
        aria-hidden
      />

      <div className="relative mx-auto grid max-w-7xl items-center gap-10 px-4 py-12 md:py-16 lg:grid-cols-[1.05fr_1fr] lg:gap-14 lg:px-8">
        {/* copy side */}
        <div className="reveal in">
          {/* <p className="flex w-fit items-center gap-2 rounded-full border border-secondary-500/40 bg-primary-800/80 px-3.5 py-1.5 text-[12px] font-extrabold text-secondary-300">
            <span className="relative flex h-2 w-2">
              <span className="absolute h-2 w-2 animate-ping rounded-full bg-secondary-400 opacity-70" />
              <span className="h-2 w-2 rounded-full bg-secondary-400" />
            </span>
            {t("warehouse")}
          </p> */}

          <h1 className="mt-5 font-display text-[40px] font-black leading-[1.15] text-background sm:text-[52px] lg:text-[58px]">
            {t("titleBefore")}
            <span className="relative mx-3 inline-block text-secondary-400">
              {t("titleAccent")}
              <svg className="absolute -bottom-2 right-0 w-full" viewBox="0 0 120 10" fill="none" aria-hidden>
                <path d="M3 7c22-5 66-6 114-2" stroke="#FF6A00" strokeWidth="3" strokeLinecap="round" />
              </svg>
            </span>
            <span className="mx-1">{t("titleAfter")}</span><br />
            {t("titleEnd")}
          </h1>

          <p className="mt-5 max-w-xl text-[15.5px] font-medium leading-8 text-primary-100/85">
            {t("description")}
          </p>

          <div className="mt-7 flex flex-wrap items-center gap-3">
            <button
              onClick={() => scrollTo("categories")}
              className="group flex h-13 items-center gap-2.5 rounded-xl bg-secondary-500 px-6 py-3.5 font-display text-[15px] font-extrabold text-primary-950 transition-all duration-300 hover:bg-secondary-400 hover:shadow-[0_10px_30px_-8px_rgba(255,106,0,0.6)] active:scale-95"
            >
              {t("shopSections")}
              <IconArrow size={18} className="transition-transform duration-300 group-hover:-translate-x-1" />
            </button>
            <button
              onClick={() => scrollTo("why-us")}
              className="flex items-center gap-2 rounded-xl border-2 border-primary-600 px-6 py-3 font-display text-[15px] font-extrabold text-background transition-all duration-300 hover:border-secondary-500 hover:text-secondary-300 active:scale-95"
            >
              {t("freeConsultation")}
            </button>
          </div>

          {/* shipping ticker chip */}
          <div className="mt-8 flex w-fit items-center gap-3 rounded-xl border border-primary-700 bg-primary-800/70 py-2.5 pr-4 pl-2.5">
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-success/20 text-success">
              <IconTruck size={19} />
            </span>
            <span className="text-[13px] font-bold text-primary-100">
              {t("lastShipment")}
              <span key={shipTo} className="anim-rise mx-1.5 inline-block font-display font-black text-secondary-400">
                {shipTo}
              </span>
              {t("within48")}
            </span>
            <span className="flex items-center gap-1 rounded-lg bg-primary-950 px-2 py-1 text-[10.5px] font-extrabold text-success">
              <IconCheck size={12} />
              {t("delivered")}
            </span>
          </div>

          <div className="mt-7 flex flex-wrap gap-2.5">
            {trustChips.map((c) => (
              <span
                key={c.label}
                className="flex items-center gap-1.5 rounded-full bg-primary-800/80 px-3 py-1.5 text-[12px] font-bold text-primary-100 ring-1 ring-primary-700"
              >
                <span className="text-secondary-400">{c.icon}</span>
                {c.label}
              </span>
            ))}
          </div>
        </div>

        {/* visual side */}
        <div className="reveal in relative" style={{ "--rv-delay": "120ms" } as CSSProperties}>
          <div className="relative overflow-hidden rounded-2xl ring-1 ring-secondary-500/25 shadow-lift">
            <div className="aspect-[4/3] overflow-hidden">
              <Image src={IMG.hero} alt={t("heroImageAlt")} width={1200} height={900} priority className="anim-kenburns h-full w-full object-cover" />
            </div>

            {/* steam wisps */}
            <div className="pointer-events-none absolute right-[24%] top-6 flex gap-2" aria-hidden>
              {[0, 1, 2].map((i) => (
                <span
                  key={i}
                  className="steam-wisp h-8 w-1 rounded-full bg-background/60 blur-[2px]"
                  style={{ animationDelay: `${i * 0.9}s` }}
                />
              ))}
            </div>

            {/* floating spec card */}
            <div className="anim-float absolute right-4 top-4 rounded-xl bg-primary-950/85 px-3.5 py-2.5 backdrop-blur ring-1 ring-secondary-500/30">
              <p className="text-[10.5px] font-bold text-secondary-300">{t("mostRequested")}</p>
              <p className="font-display text-[13.5px] font-extrabold text-background">{t("featuredProduct")}</p>
            </div>

            {/* bottom info bar */}
            <div className="flex items-center justify-between gap-3 border-t border-primary-700/60 bg-primary-950/90 px-4 py-3 backdrop-blur">
              <div className="flex -space-x-2.5">
                {["م", "ع", "س"].map((ch, i) => (
                  <span
                    key={i}
                    className="grid h-8 w-8 place-items-center rounded-full border-2 border-primary-950 bg-primary-700 text-[12px] font-black text-secondary-300"
                  >
                    {ch}
                  </span>
                ))}
              </div>
              <p className="text-[12px] font-bold text-primary-100">
                {locale === "ar" ? "انضم إلى" : "Join"} <span className="font-display font-black text-secondary-400">+١٢٠</span> {t("projectsEquipped")}
              </p>
            </div>

            {/* <RotatingStamp /> */}
          </div>
        </div>
      </div>

      {/* category quick strip */}
      <div className="relative border-t border-primary-700/60 bg-primary-950/60">
        <div className="mx-auto flex max-w-7xl items-center gap-6 overflow-x-auto no-scrollbar px-4 py-3.5 lg:px-8">
          <span className="shrink-0 text-[12px] font-extrabold text-secondary-400">{t("quickShop")}</span>
          {categories.map((c) => (
            <button
              key={c.id}
              onClick={() => document.getElementById(`rail-${c.id}`)?.scrollIntoView({ behavior: "smooth" })}
              className="shrink-0 text-[12.5px] font-bold text-primary-100/80 transition-colors hover:text-secondary-300"
            > {c.name}
            </button>
          ))}
        </div>
      </div>
    </section>
  );
}
