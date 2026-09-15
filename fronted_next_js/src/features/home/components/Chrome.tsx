"use client";

import Image from "next/image";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { categories as staticCategories, formatPrice, type Category, type Product } from "../catalog";
import { useShopSettings } from "../use-settings";
import { useCart, resolveProduct } from "@/stores/cart";
import { useMounted } from "@/hooks/use-home";
import { Logo } from "./Header";
import {
  IconCart,
  IconCheck,
  IconClock,
  IconGrid,
  IconHome,
  IconMinus,
  IconPin,
  IconPlus,
  IconSearch,
  IconSend,
  IconTrash,
  IconTruck,
  IconWhatsApp,
  IconX,
} from "@/features/home/components/Icons";

/* ================= toasts ================= */
export function Toasts() {
  const { toasts } = useCart();
  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-24 z-[95] flex flex-col items-center gap-2 md:bottom-8">
      {toasts.map((t) => (
        <div
          key={t.id}
          className="anim-toast flex items-center gap-2.5 rounded-xl bg-primary-950 py-2.5 pr-3 pl-4 text-[13px] font-bold text-background shadow-lift ring-1 ring-secondary-500/40"
        >
          <span className="grid h-6 w-6 place-items-center rounded-full bg-success text-white">
            <IconCheck size={13} />
          </span>
          {t.msg}
        </div>
      ))}
    </div>
  );
}

/* ================= cart drawer ================= */
export function CartDrawer({ byId }: { byId: Map<string, Product> }) {
  const t = useTranslations("home.cart");
  const { lines, drawerOpen, setDrawerOpen, inc, dec, remove, clear, subtotal, count } = useCart();
  const [placed, setPlaced] = useState(false);
  const [orderNo, setOrderNo] = useState<string | null>(null);
  const { settings } = useShopSettings();
  const FREE_SHIPPING_THRESHOLD = settings.free_shipping_threshold;
  const vat = Math.round(subtotal * 0.15);
  const remaining = Math.max(0, FREE_SHIPPING_THRESHOLD - subtotal);
  const progress = Math.min(100, (subtotal / FREE_SHIPPING_THRESHOLD) * 100);

  const closeDrawer = () => {
    setPlaced(false);
    setOrderNo(null);
    setDrawerOpen(false);
  };

  useEffect(() => {
    document.body.style.overflow = drawerOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [drawerOpen]);

  if (!drawerOpen) return null;

  const placeOrder = () => {
    setOrderNo(`TG-${Math.floor(100000 + Math.random() * 900000)}`);
    setPlaced(true);
  };

  return (
    <div className="fixed inset-0 z-[80]">
      <button
        aria-label={t("close")}
        onClick={closeDrawer}
        className="anim-fade absolute inset-0 h-full w-full bg-primary-950/55 backdrop-blur-[2px]"
      />
      <aside className="anim-drawer absolute inset-y-0 left-0 flex w-full max-w-md flex-col bg-background shadow-lift" role="dialog" aria-label={t("title")}>
        {/* head */}
        <div className="flex items-center justify-between border-b border-muted-200 bg-surface px-5 py-4">
          <h2 className="flex items-center gap-2.5 font-display text-[18px] font-black text-muted-900">
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary-900 text-secondary-400">
              <IconCart size={18} />
            </span>
            {t("title")}
            <span className="rounded-full bg-primary-50 px-2.5 py-0.5 text-[12px] font-extrabold text-primary-800 tabular">{t("items", { count })}</span>
          </h2>
          <button
            onClick={closeDrawer}
            className="grid h-9 w-9 place-items-center rounded-lg border border-muted-200 text-muted-500 transition-colors hover:border-danger hover:text-danger"
            aria-label={t("close")}
          >
            <IconX size={17} />
          </button>
        </div>

        {placed ? (
          /* success state */
          <div className="flex flex-1 flex-col items-center justify-center gap-4 px-8 text-center">
            <span className="grid h-20 w-20 place-items-center rounded-full bg-success/15 text-success">
              <IconCheck size={38} />
            </span>
            <h3 className="font-display text-[22px] font-black text-muted-900">{t("successTitle")}</h3>
            <p className="text-[14px] font-medium leading-7 text-muted-500">
              {t("successDesc")}
              <span className="mx-1 font-black text-primary-800 tabular">{orderNo}</span>
            </p>
            <button
              onClick={() => {
                clear();
                closeDrawer();
              }}
              className="mt-2 rounded-xl bg-primary-900 px-6 py-3 font-display text-[14px] font-extrabold text-secondary-300 transition-all hover:bg-primary-800 active:scale-95"
            >
              {t("continue")}
            </button>
          </div>
        ) : lines.length === 0 ? (
          /* empty state */
          <div className="flex flex-1 flex-col items-center justify-center gap-4 px-8 text-center">
            <span className="relative grid h-24 w-24 place-items-center rounded-full bg-primary-50 text-primary-300">
              <IconCart size={40} />
              <span className="absolute -left-1 top-1 grid h-7 w-7 place-items-center rounded-full bg-secondary-500 text-[14px] font-black text-primary-950">
                ؟
              </span>
            </span>
            <h3 className="font-display text-[20px] font-black text-muted-900">{t("emptyTitle")}</h3>
            <p className="text-[13.5px] font-medium leading-6 text-muted-500">
              {t("emptyDesc", { amount: formatPrice(FREE_SHIPPING_THRESHOLD) })}
            </p>
            <button
              onClick={() => {
                setDrawerOpen(false);
                document.getElementById("categories")?.scrollIntoView({ behavior: "smooth" });
              }}
              className="mt-2 rounded-xl bg-secondary-500 px-6 py-3 font-display text-[14px] font-extrabold text-primary-950 transition-all hover:bg-secondary-400 active:scale-95"
            >
              {t("startShopping")}
            </button>
          </div>
        ) : (
          <>
            {/* free shipping progress */}
            <div className="border-b border-muted-200 bg-surface px-5 py-3.5">
              {remaining > 0 ? (
                <p className="flex items-center gap-2 text-[12.5px] font-bold text-muted-700">
                  <IconTruck size={17} className="text-secondary-600" />
                  {t("freeShippingRemaining", { amount: formatPrice(remaining) })}
                </p>
              ) : (
                <p className="flex items-center gap-2 text-[12.5px] font-extrabold text-success">
                  <IconTruck size={17} />
                  {t("freeShippingDone")}
                </p>
              )}
              <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted-200/70">
                <div
                  className={`h-full rounded-full transition-all duration-700 ${remaining > 0 ? "bg-secondary-500" : "bg-success"}`}
                  style={{ width: `${progress}%` }}
                />
              </div>
            </div>

            {/* lines */}
            <div className="flex-1 space-y-3 overflow-y-auto px-5 py-4">
              {lines.map((l) => {
                // Resolve via the shared live lookup first, then the cart
                // store's fallback. If both miss (stale localStorage line
                // before the catalog loaded) we render a minimal placeholder
                // so the user can still see the line and remove it.
                const p = byId.get(l.id) ?? resolveProduct(l.id);
                if (!p) {
                  return (
                    <div key={l.id} className="anim-rise flex gap-3 rounded-xl border border-muted-200 bg-surface p-3">
                      <div className="grid h-18 w-18 shrink-0 place-items-center rounded-lg bg-muted-200 text-muted-400">؟</div>
                      <div className="min-w-0 flex-1">
                        <h4 className="truncate text-[13px] font-extrabold text-muted-900">{l.id}</h4>
                        <p className="mt-0.5 text-[11.5px] text-muted-400">×{l.qty}</p>
                      </div>
                      <button onClick={() => remove(l.id)} className="self-start text-muted-300 transition-colors hover:text-danger" aria-label={t("remove", { name: l.id })}>
                        <IconTrash size={16} />
                      </button>
                    </div>
                  );
                }
                return (
                  <div key={l.id} className="anim-rise flex gap-3 rounded-xl border border-muted-200 bg-surface p-3">
                    <Image src={p.image} alt={p.name} width={72} height={72} className="h-18 w-18 shrink-0 rounded-lg object-cover" />
                    <div className="min-w-0 flex-1">
                      <h4 className="truncate text-[13px] font-extrabold text-muted-900">{p.name}</h4>
                      <p className="mt-0.5 text-[11.5px] text-muted-400">{p.spec}</p>
                      <div className="mt-2 flex items-center justify-between">
                        <div className="flex items-center gap-1 rounded-lg border border-muted-200">
                          <button onClick={() => inc(l.id)} className="grid h-7 w-7 place-items-center text-primary-800 transition-colors hover:text-secondary-600" aria-label={t("increase")}>
                            <IconPlus size={13} />
                          </button>
                          <span className="w-6 text-center text-[12.5px] font-black tabular">{l.qty}</span>
                          <button onClick={() => dec(l.id)} className="grid h-7 w-7 place-items-center text-primary-800 transition-colors hover:text-danger" aria-label={t("decrease")}>
                            <IconMinus size={13} />
                          </button>
                        </div>
                        <span className="font-display text-[14.5px] font-extrabold text-primary-800 tabular">
                          {formatPrice(p.price * l.qty)} <span className="text-[10.5px] text-muted-400">SAR</span>
                        </span>
                      </div>
                    </div>
                    <button onClick={() => remove(l.id)} className="self-start text-muted-300 transition-colors hover:text-danger" aria-label={t("remove", { name: p.name })}>
                      <IconTrash size={16} />
                    </button>
                  </div>
                );
              })}
            </div>

            {/* summary */}
            <div className="border-t border-muted-200 bg-surface px-5 py-4">
              <div className="space-y-1.5 text-[13px] font-bold text-muted-500">
                <p className="flex justify-between"><span>{t("subtotal")}</span><span className="tabular">{formatPrice(subtotal)} SAR</span></p>
                <p className="flex justify-between"><span>{t("vat")}</span><span className="tabular">{formatPrice(vat)} SAR</span></p>
                <p className="flex justify-between"><span>{t("shipping")}</span><span className={remaining > 0 ? "" : "text-success"}>{remaining > 0 ? t("calculatedAtShipping") : t("free")}</span></p>
              </div>
              <p className="mt-3 flex items-baseline justify-between border-t border-dashed border-muted-200 pt-3">
                <span className="font-display text-[15px] font-black text-muted-900">{t("total")}</span>
                <span className="font-display text-[22px] font-black text-primary-800 tabular">
                  {formatPrice(subtotal + vat)} <span className="text-[12px]">SAR</span>
                </span>
              </p>
              <button
                onClick={placeOrder}
                className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-primary-900 py-3.5 font-display text-[15px] font-extrabold text-secondary-300 transition-all duration-300 hover:bg-primary-800 active:scale-[0.98]"
              >
                {t("checkout")}
                <IconSend size={17} />
              </button>
              <div className="mt-3 flex items-center justify-center gap-2 text-[11px] font-bold text-muted-400">
                <span className="rounded border border-muted-200 px-1.5 py-0.5">مدى</span>
                <span className="rounded border border-muted-200 px-1.5 py-0.5">Visa</span>
                <span className="rounded border border-muted-200 px-1.5 py-0.5">Apple Pay</span>
                <span className="rounded border border-muted-200 px-1.5 py-0.5">تمارا</span>
                <span className="rounded border border-muted-200 px-1.5 py-0.5">{t("bankTransfer")}</span>
              </div>
            </div>
          </>
        )}
      </aside>
    </div>
  );
}

/* ================= chat widget ================= */
type Msg = { from: "store" | "user"; text: string };

type ChatTranslator = ReturnType<typeof useTranslations>;

const cannedReply = (q: string, t: ChatTranslator) =>
  q === t("questions.0") ? t("quoteReply") : q === t("questions.1") ? t("consultReply") : q === t("questions.2") ? t("trackReply") : t("vatReply");

export function ChatWidget() {
  const t = useTranslations("home.chat");
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState<Msg[]>([{ from: "store", text: t("welcome") }]);
  const quickQuestions = t.raw("questions") as string[];
  const [input, setInput] = useState("");
  const [typing, setTyping] = useState(false);
  const bodyRef = useRef<HTMLDivElement>(null);
  const timeouts = useRef<number[]>([]);

  useEffect(() => {
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [msgs, typing, open]);

  useEffect(() => () => timeouts.current.forEach(clearTimeout), []);

  const send = (text: string) => {
    if (!text.trim()) return;
    setMsgs((m) => [...m, { from: "user", text: text.trim() }]);
    setInput("");
    setTyping(true);
    timeouts.current.push(
      window.setTimeout(() => {
        setTyping(false);
        setMsgs((m) => [...m, { from: "store", text: cannedReply(text, t) }]);
      }, 1300)
    );
  };

  return (
    <>
      {/* panel */}
      {open && (
        <div className="anim-rise fixed bottom-24 left-4 z-[70] flex h-[26rem] w-[20.5rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-muted-200 bg-background shadow-lift md:bottom-28 md:left-6">
          <div className="flex items-center gap-3 bg-primary-900 px-4 py-3">
            <span className="relative grid h-10 w-10 place-items-center rounded-full bg-secondary-500 font-display text-[16px] font-black text-primary-950">
              TG
              <span className="pulse-dot absolute -bottom-0.5 -left-0.5 h-3 w-3 rounded-full border-2 border-primary-900 bg-success" />
            </span>
            <div className="flex-1 leading-tight">
              <p className="font-display text-[14.5px] font-extrabold text-background">{t("team")}</p>
              <p className="text-[11px] font-bold text-success">{t("online")}</p>
            </div>
            <button onClick={() => setOpen(false)} className="grid h-8 w-8 place-items-center rounded-lg text-primary-100/70 transition-colors hover:bg-primary-800 hover:text-background" aria-label={t("close")}>
              <IconX size={16} />
            </button>
          </div>

          <div ref={bodyRef} className="flex-1 space-y-2.5 overflow-y-auto bg-primary-50/50 p-3.5">
            {msgs.map((m, i) => (
              <div key={i} className={`flex ${m.from === "user" ? "justify-start" : "justify-end"}`}>
                <p
                  className={`max-w-[85%] rounded-2xl px-3.5 py-2 text-[12.5px] font-bold leading-6 ${
                    m.from === "user" ? "rounded-bl-sm bg-primary-900 text-background" : "rounded-br-sm border border-muted-200 bg-surface text-muted-700"
                  }`}
                >
                  {m.text}
                </p>
              </div>
            ))}
            {typing && (
              <div className="flex justify-end">
                <span className="flex gap-1 rounded-2xl rounded-br-sm border border-muted-200 bg-surface px-3.5 py-2.5">
                  {[0, 1, 2].map((i) => (
                    <span key={i} className="typing-dot h-1.5 w-1.5 rounded-full bg-muted-400" style={{ animationDelay: `${i * 0.15}s` }} />
                  ))}
                </span>
              </div>
            )}
          </div>

          <div className="border-t border-muted-200 bg-surface p-3">
            <div className="mb-2.5 flex flex-wrap gap-1.5">
              {quickQuestions.map((q) => (
                <button
                  key={q}
                  onClick={() => send(q)}
                  className="rounded-full border border-primary-300 bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-800 transition-all hover:border-secondary-500 hover:bg-secondary-100 active:scale-95"
                >
                  {q}
                </button>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && send(input)}
                placeholder={t("input")}
                className="h-10 flex-1 rounded-xl border border-muted-200 bg-background px-3 text-[12.5px] font-bold outline-none transition-colors focus:border-secondary-500"
              />
              <button
                onClick={() => send(input)}
                className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-success text-white transition-all hover:brightness-110 active:scale-90"
                aria-label={t("send")}
              >
                <IconSend size={16} className="-scale-x-100" />
              </button>
            </div>
          </div>
        </div>
      )}

      {/* launcher */}
      <button
        onClick={() => setOpen((o) => !o)}
        aria-label={open ? t("close") : t("whatsapp")}
        className="group fixed bottom-20 left-4 z-[70] grid h-14 w-14 place-items-center rounded-full bg-success text-white shadow-lift transition-all duration-300 hover:scale-110 active:scale-95 md:bottom-7 md:left-6"
      >
        {open ? <IconX size={22} /> : <IconWhatsApp size={25} />}
        {!open && <span className="pulse-dot absolute inset-0 rounded-full" />}
        {!open && (
          <span className="absolute right-full mr-3 hidden whitespace-nowrap rounded-lg bg-primary-950 px-3 py-1.5 text-[12px] font-extrabold text-secondary-300 opacity-0 shadow-soft transition-opacity duration-300 group-hover:opacity-100 md:block">
            {t("help")}
          </span>
        )}
      </button>
    </>
  );
}

/* ================= mobile bottom nav ================= */
export function MobileNav() {
  const t = useTranslations("home.mobile");
  const { count, setDrawerOpen, badgeKey } = useCart();
  const [active, setActive] = useState("home");
  const mounted = useMounted();

  const items = [
    { id: "home", label: t("home"), icon: <IconHome size={21} />, go: () => document.getElementById("top")?.scrollIntoView({ behavior: "smooth" }) },
    { id: "cats", label: t("categories"), icon: <IconGrid size={21} />, go: () => document.getElementById("categories")?.scrollIntoView({ behavior: "smooth" }) },
    { id: "cart", label: t("cart"), icon: <IconCart size={21} />, go: () => setDrawerOpen(true) },
    { id: "search", label: t("search"), icon: <IconSearch size={21} />, go: () => { window.scrollTo({ top: 0, behavior: "smooth" }); window.setTimeout(() => document.getElementById("site-search")?.focus(), 400); } },
    { id: "why", label: t("why"), icon: <IconClock size={21} />, go: () => document.getElementById("why-us")?.scrollIntoView({ behavior: "smooth" }) },
  ];

  return (
    <nav className="fixed inset-x-0 bottom-0 z-[60] border-t border-muted-200 bg-surface/95 backdrop-blur md:hidden" aria-label={t("navigation")}>
      <div className="grid grid-cols-5">
        {items.map((it) => (
          <button
            key={it.id}
            onClick={() => {
              setActive(it.id);
              it.go();
            }}
            className={`relative flex flex-col items-center gap-1 py-2.5 transition-colors ${
              active === it.id ? "text-primary-900" : "text-muted-400"
            }`}
          >
            <span className={`relative transition-transform duration-200 ${active === it.id ? "-translate-y-0.5" : ""}`}>
              {it.icon}
              {it.id === "cart" && mounted && count > 0 && (
                <span key={badgeKey} className="anim-badge-pop absolute -left-2 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-secondary-500 px-0.5 text-[9.5px] font-black text-primary-950 tabular">
                  {count}
                </span>
              )}
            </span>
            <span className="text-[10px] font-extrabold">{it.label}</span>
            {active === it.id && <span className="absolute top-0 h-0.5 w-8 rounded-full bg-secondary-500" />}
          </button>
        ))}
      </div>
    </nav>
  );
}

/* ================= footer ================= */
export function Footer({ categories }: { categories?: Category[] }) {
  const t = useTranslations("home.footer");
  const [email, setEmail] = useState("");
  const [subscribed, setSubscribed] = useState(false);

  const displayCategories = (categories && categories.length > 0) ? categories : staticCategories;

  const cols = [
    {
      title: t("quickLinks"),
      links: [t("home"), t("allCategories"), t("offers"), t("brands"), t("faq"), t("returns")],
    },
    {
      title: t("topCategories"),
      links: displayCategories.slice(0, 6).map((c: Category) => c.name),
      ids: displayCategories.slice(0, 6).map((c: Category) => `rail-${c.id}`),
    },
  ];

  return (
    <footer className="bg-primary-950 pb-24 pt-14 text-primary-100 md:pb-10">
      <div className="mx-auto max-w-7xl px-4 lg:px-8">
        <div className="grid gap-10 md:grid-cols-[1.3fr_1fr_1fr_1.3fr]">
          {/* brand */}
          <div>
            <Logo light />
            <p className="mt-4 max-w-xs text-[13px] font-medium leading-7 text-primary-100/70">
              {t("description")}
            </p>
            <div className="mt-5 space-y-2.5 text-[12.5px] font-bold">
              <p className="flex items-center gap-2.5">
                <IconPin size={16} className="text-secondary-400" />
                {t("address")}
              </p>
              <p className="flex items-center gap-2.5">
                <IconClock size={16} className="text-secondary-400" />
                {t("hours")}
              </p>
            </div>
          </div>

          {/* links */}
          {cols.map((col) => (
            <div key={col.title}>
              <h4 className="mb-4 font-display text-[15px] font-extrabold text-background">{col.title}</h4>
              <ul className="space-y-2.5">
                {col.links.map((l, i) => (
                  <li key={l}>
                    <button
                      onClick={() => {
                        const target = col.ids?.[i];
                        if (target) document.getElementById(target)?.scrollIntoView({ behavior: "smooth" });
                        else document.getElementById("top")?.scrollIntoView({ behavior: "smooth" });
                      }}
                      className="text-[13px] font-bold text-primary-100/65 transition-colors hover:text-secondary-400"
                    >
                      {l}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}

          {/* newsletter */}
          <div>
            <h4 className="mb-4 font-display text-[15px] font-extrabold text-background">{t("newsletterTitle")}</h4>
            <p className="text-[12.5px] font-medium leading-6 text-primary-100/65">
              {t("newsletterDesc")}
            </p>
            {subscribed ? (
              <p className="mt-4 flex items-center gap-2 rounded-xl bg-primary-800/80 px-4 py-3 text-[13px] font-extrabold text-success">
                <IconCheck size={16} />
                {t("subscribed")}
              </p>
            ) : (
              <form
                className="mt-4 flex overflow-hidden rounded-xl border border-primary-700 bg-primary-900 focus-within:border-secondary-500"
                onSubmit={(e) => {
                  e.preventDefault();
                  if (email.trim()) setSubscribed(true);
                }}
              >
                <input
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  type="email"
                  required
                  placeholder={t("email")}
                  className="w-full bg-transparent px-4 py-3 text-[13px] font-bold text-background outline-none placeholder:text-primary-100/40"
                />
                <button type="submit" className="shrink-0 bg-secondary-500 px-5 font-display text-[13px] font-extrabold text-primary-950 transition-colors hover:bg-secondary-400">
                  {t("subscribe")}
                </button>
              </form>
            )}
            <div className="mt-5 flex gap-2 text-[11px] font-extrabold text-primary-100/60">
              {["مدى", "Visa", "Mastercard", "Apple Pay", "تمارا"].map((p) => (
                <span key={p} className="rounded-md border border-primary-700 bg-primary-900 px-2.5 py-1.5">{p}</span>
              ))}
            </div>
          </div>
        </div>

        <div className="mt-12 flex flex-wrap items-center justify-between gap-3 border-t border-primary-800 pt-6 text-[12px] font-bold text-primary-100/50">
          <p>{t("copyright")}</p>
          <p className="tabular">{t("tax")}</p>
        </div>
      </div>
    </footer>
  );
}
