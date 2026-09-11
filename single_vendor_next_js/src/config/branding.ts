/**
 * Centralized branding for TAGAHAYEEZ (ar: تجاهيز).
 *
 * Single source of truth for store identity:
 * - names / taglines / contact: see `src/config/branding.ts`
 * - logo files: `public/branding/*`
 *
 * Single source of truth for colors: this `@theme` block.
 * To re-skin the whole store in the future, change ONLY the
 * `--brand-*` base values + the semantic scale below.
 * Components must use ONLY semantic utilities:
 * `primary-*`, `secondary-*`, `background`, `surface`,
 * `foreground`, `muted-*`, `success`, `warning`, `danger`.
 */

export const branding = {
  name: {
    en: "TAGAHAYEEZ",
    ar: "تجاهيز",
  },
  tagline: {
    en: "Commercial equipment for restaurants, cafés and bakeries",
    ar: "معدات تجارية للمطاعم والمقاهي والمخابز",
  },
  description: {
    en: "The commercial equipment house in Saudi Arabia — from an espresso machine to a complete bakery line, with authorized agencies and a technical team that delivers to your door.",
    ar: "بيت المعدات التجارية في السعودية — من ماكينة الإسبريسو إلى خط مخبز متكامل، بوكالات معتمدة وفريق فني يوصلك حتى باب محلك.",
  },
  logos: {
    /** Used in the storefront header (light background). */
    header: "/branding/logo_header.png",
    /** Used in the footer (dark background). */
    footer: "/branding/logo_footer.png",
    /** Used for metadata / OpenGraph / favicon-adjacent surfaces. */
    metadata: "/branding/logo_metadata.png",
  },
  contact: {
    phone: "920 012 345",
    phoneHref: "tel:920000000",
    whatsapp: "https://wa.me/966500000000",
    address: {
      en: "Riyadh,TAGAHAYEEZ warehouses",
      ar: "الرياض، مستودعات تجاهيز",
    },
  },
  siteUrl: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
} as const;

export type Branding = typeof branding;
