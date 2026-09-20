"use client";

import { useEffect, useState } from "react";
import { getHomepage, type ShopSettings } from "@/features/home/api";

/** Empty shape used until Laravel responds; it contains no mock storefront data. */
const fallbackSettings: ShopSettings = {
  store: {
    name: "",
    phone: null,
    logo: null,
    footer_logo: null,
    fav_icon: null,
    copyright_text: null,
    currency: "",
  },
  header: null,
  footer: null,
  features:[],
  stats: [],
  ticker_items: [],
  free_shipping_threshold: 0,
  currency: "",
  maintenance_mode: false,
};

let cached: ShopSettings | null = null;
let inflight: Promise<ShopSettings> | null = null;

async function loadSettings(): Promise<ShopSettings> {
  if (cached) return cached;
  if (!inflight) {
    inflight = getHomepage()
      .then((settings) => {
        cached = settings;
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
 * Live storefront settings from `GET {NEXT_PUBLIC_API_URL}/v1/homepage`.
 * Returns an empty settings shape until the API responds (or when the API is
 * unreachable), so rendered business content never claims to be live data.
 *
 * IMPORTANT: the initial state is ALWAYS `fallbackSettings` (never the
 * module-level `cached` value) so the first client render is identical to
 * the server prerender. The cached/live value is only applied inside
 * `useEffect` (after hydration), otherwise client-side navigation after a
 * live load would hydrate with different ticker/feature data than the
 * server HTML and throw a React hydration-mismatch error.
 */
export function setCachedSettings(settings: ShopSettings) {
  cached = settings;
}

export function useShopSettings(initialSettings?: ShopSettings | null): { settings: ShopSettings; live: boolean } {
  if (initialSettings) {
    cached = initialSettings;
  }
  const [settings, setSettings] = useState<ShopSettings>(initialSettings ?? cached ?? fallbackSettings);
  const [live, setLive] = useState((initialSettings ?? cached) !== null && (initialSettings ?? cached) !== fallbackSettings);

  useEffect(() => {
    let cancelled = false;
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
