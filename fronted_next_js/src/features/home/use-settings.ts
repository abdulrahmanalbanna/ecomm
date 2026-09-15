"use client";

import { useEffect, useState } from "react";
import { getPublicSettings, type ShopSettings } from "@/features/home/api";
import { FREE_SHIPPING_THRESHOLD, features, stats, tickerItems } from "@/features/home/catalog";

/** Offline fallback built from the static `catalog.ts` data. */
const fallbackSettings: ShopSettings = {
  store: {
    name: "TAGAHAYEEZ",
    phone: null,
    logo: null,
    footer_logo: null,
    fav_icon: null,
    copyright_text: null,
    currency: "SAR",
  },
  header: null,
  footer: null,
  features: features.map((f) => ({ id: f.id, title: f.title, desc: f.desc, icon: f.icon })),
  stats: stats.map((s) => ({ value: s.value, suffix: s.suffix, label: s.label })),
  ticker_items: [...tickerItems],
  free_shipping_threshold: FREE_SHIPPING_THRESHOLD,
  currency: "SAR",
  maintenance_mode: false,
};

let cached: ShopSettings | null = null;
let inflight: Promise<ShopSettings> | null = null;

async function loadSettings(): Promise<ShopSettings> {
  if (cached) return cached;
  if (!inflight) {
    inflight = getPublicSettings()
      .then((res) => {
        cached = res.data;
        return cached;
      })
      .catch(() => fallbackSettings)
      .finally(() => {
        inflight = null;
      });
  }
  return inflight;
}

/**
 * Live storefront settings from `GET {NEXT_PUBLIC_API_URL}/v1/settings`.
 * Returns the static `catalog.ts` fallback until the API responds (or when
 * the API is unreachable), so the storefront always renders.
 *
 * IMPORTANT: the initial state is ALWAYS `fallbackSettings` (never the
 * module-level `cached` value) so the first client render is identical to
 * the server prerender. The cached/live value is only applied inside
 * `useEffect` (after hydration), otherwise client-side navigation after a
 * live load would hydrate with different ticker/feature data than the
 * server HTML and throw a React hydration-mismatch error.
 */
export function useShopSettings(): { settings: ShopSettings; live: boolean } {
  const [settings, setSettings] = useState<ShopSettings>(fallbackSettings);
  const [live, setLive] = useState(false);

  useEffect(() => {
    let cancelled = false;
    // NOTE: `loadSettings()` resolves the module-level `cached` value (if
    // any) asynchronously, so the live settings are only applied after
    // hydration — never during the first client render.
    loadSettings().then((s) => {
      if (cancelled) return;
      setSettings(s);
      setLive(s !== fallbackSettings);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  return { settings, live };
}

export { fallbackSettings };
