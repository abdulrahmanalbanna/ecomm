"use client";

import Image from "next/image";

import { useRef, useState, type CSSProperties } from "react";
import { useTranslations } from "next-intl";
import { formatPrice, type Product } from "../catalog";
import { useCart } from "@/stores/cart";
import { IconArrow, IconCart, IconCheck, IconStar, IconTruck } from "@/features/home/components/Icons";

export function Stars({ rating }: { rating: number }) {
  return (
    <span className="inline-flex items-center gap-0.5 text-secondary-500">
      {Array.from({ length: 5 }).map((_, i) => (
        <IconStar key={i} size={11} className={i < Math.round(rating) ? "" : "opacity-25"} />
      ))}
    </span>
  );
}

/**
 * `name` is required because cart lines are now keyed by the backend
 * `public_id` UUID, which the static fallback catalog cannot resolve.
 * Callers must pass the product name from the shared live catalog.
 */
export function AddButton({ id, name, compact = false }: { id: string; name: string; compact?: boolean }) {
  const t = useTranslations("home.product");
  const { add } = useCart();
  const [done, setDone] = useState(false);
  const timeoutRef = useRef<number>(0);

  const onClick = () => {
    add(id, t("addedToast", { name }));
    setDone(true);
    window.clearTimeout(timeoutRef.current);
    timeoutRef.current = window.setTimeout(() => setDone(false), 1200);
  };

  return (
    <button
      onClick={onClick}
      aria-label={t("add")}
      className={`group/add relative inline-flex shrink-0 items-center justify-center gap-1.5 overflow-hidden rounded-lg font-bold text-white transition-all duration-300 active:scale-95 ${
        done ? "bg-success" : "bg-primary-800 hover:bg-secondary-500 hover:text-primary-950"
      } ${compact ? "h-9 w-9 rounded-md" : "h-10 px-4 text-[13px]"}`}
    >
      {compact ? (
        done ? <IconCheck size={17} /> : <IconCart size={17} />
      ) : (
        <>
          <span className={`transition-transform duration-300 ${done ? "-translate-y-6 opacity-0" : ""}`}>
            <IconCart size={16} />
          </span>
          <span className={`transition-all duration-300 ${done ? "translate-y-6 opacity-0 absolute" : ""}`}>
            {t("add")}
          </span>
          {done && (
            <span className="absolute inset-0 flex items-center justify-center gap-1 anim-rise">
              <IconCheck size={16} />
              {t("added")}
            </span>
          )}
        </>
      )}
    </button>
  );
}

export function ProductCard({ p, style }: { p: Product; style?: CSSProperties }) {
  const t = useTranslations("home.product");
  const discount = p.oldPrice ? Math.round(((p.oldPrice - p.price) / p.oldPrice) * 100) : 0;
  const badgeLabel = p.badge === "عرض خاص" ? t("special") : p.badge === "جديد" ? t("new") : p.badge === "الأكثر مبيعًا" ? t("best") : p.badge;
  return (
    <article
      style={style}
      className="group relative flex w-full flex-col rounded-xl border border-muted-200/70 bg-surface p-3 transition-all duration-300 hover:-translate-y-1.5 hover:border-primary-300 hover:shadow-lift"
    >
      {/* badges */}
      <div className="absolute top-3 right-3 z-10 flex flex-col items-start gap-1.5">
        {p.badge && (
          <span
            className={`rounded-md px-2 py-0.5 text-[10.5px] font-bold ${
              p.badge === "عرض خاص"
                ? "bg-danger text-white"
                : p.badge === "جديد"
                ? "bg-primary-700 text-secondary-300"
                : "bg-secondary-500 text-primary-950"
            }`}
          >
            {badgeLabel}
          </span>
        )}
        {discount > 0 && (
          <span className="rounded-md bg-muted-900/90 px-2 py-0.5 text-[10.5px] font-bold text-secondary-300">
            {t("discount", { percent: discount })}
          </span>
        )}
      </div>

      {/* image */}
      <div className="relative mb-3 grid aspect-square place-items-center overflow-hidden rounded-lg bg-gradient-to-b from-primary-50 to-muted-200/40">
        <Image
          src={p.image}
          alt={p.name}
          width={600}
          height={600}
          className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.07]"
        />
        <span className="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full bg-surface/95 px-2 py-0.5 text-[10.5px] font-bold text-success shadow-soft">
          <span className="h-1.5 w-1.5 rounded-full bg-success" />
          {t("available")}
        </span>
      </div>

      {/* meta */}
      <div className="flex items-center gap-1.5 text-[11px] text-muted-400">
        <Stars rating={p.rating} />
        <span className="font-bold text-muted-500">{p.rating}</span>
        <span>({p.reviews})</span>
      </div>

      <h3 className="mt-1 line-clamp-2 min-h-[2.5rem] font-display text-[13.5px] font-bold leading-6 text-muted-900">
        {p.name}
      </h3>
      <p className="mt-0.5 line-clamp-1 text-[11.5px] text-muted-400">{p.spec}</p>

      <div className="mt-2 flex flex-wrap items-baseline gap-x-2">
        {p.startsFrom && <span className="text-[11px] font-bold text-muted-400">{t("startsFrom")}</span>}
        <span className="font-display text-lg font-extrabold text-primary-800 tabular">{formatPrice(p.price)}</span>
        <span className="text-[11px] font-bold text-muted-400">SAR</span>
        {p.oldPrice && (
          <span className="text-[12px] text-muted-300 line-through tabular">{formatPrice(p.oldPrice)}</span>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between gap-2 border-t border-dashed border-muted-200 pt-3">
        {p.freeShipping ? (
          <span className="inline-flex items-center gap-1 text-[11px] font-bold text-secondary-700">
            <IconTruck size={15} />
            {t("freeShipping")}
          </span>
        ) : (
          <span className="text-[11px] text-muted-300">{t("shipsAll")}</span>
        )}
        <AddButton id={p.id} name={p.name} compact />
      </div>
    </article>
  );
}

/** Tall promo tile inserted inside product rails */
export function PromoTile({
  title,
  sub,
  cta,
  image,
  onCta,
}: {
  title: string;
  sub: string;
  cta: string;
  image: string;
  onCta: () => void;
}) {
  const promoT = useTranslations("home.promo");
  return (
    <div className="group relative w-60 shrink-0 snap-start overflow-hidden rounded-xl bg-primary-900 md:w-72">
      <Image
        src={image}
        alt=""
        fill
        sizes="(min-width: 768px) 18rem, 15rem"
        className="object-cover opacity-45 transition-all duration-700 group-hover:scale-110 group-hover:opacity-60"
      />
      <div className="pattern-dots absolute inset-0" />
      <div className="relative flex h-full min-h-[380px] flex-col justify-end p-5">
        <span className="mb-2 w-fit rounded-md bg-secondary-500 px-2 py-0.5 text-[11px] font-extrabold text-primary-950">
          {promoT("fullSetup")}
        </span>
        <h4 className="font-display text-xl font-extrabold leading-7 text-background">{title}</h4>
        <p className="mt-1 text-[12.5px] leading-5 text-primary-100/85">{sub}</p>
        <button
          onClick={onCta}
          className="mt-4 inline-flex w-fit items-center gap-2 rounded-lg bg-background px-4 py-2 text-[13px] font-extrabold text-primary-900 transition-all duration-300 hover:bg-secondary-400 active:scale-95"
        >
          {cta}
          <IconArrow size={15} />
        </button>
      </div>
    </div>
  );
}
