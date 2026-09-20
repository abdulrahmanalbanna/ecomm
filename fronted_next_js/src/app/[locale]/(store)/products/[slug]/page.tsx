import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getProductDetailServer, getRelatedServer } from "@/features/product-detail/api";
import { ProductDetailPage } from "@/features/product-detail/components/ProductDetailPage";
import { branding } from "@/config/branding";
import { getBackendOrigin } from "@/lib/media";
import type { Locale } from "@/types/locale";

/**
 * Product Detail Page route.
 *
 * Data fetching strategy:
 *   - The product is fetched **server-side** with `next: { revalidate: 3600 }`
 *     (ISR, 1 hour) inside `getProductDetailServer`. A cache miss renders the
 *     `loading.tsx` skeleton; a 404 returns `null` → `notFound()`.
 *   - Related products are prefetched the same way so the "You might also
 *     like" rail paints with the initial HTML.
 *   - Reviews are *not* fetched here: they stream client-side through
 *     `useReviewsInfinite` so a slow reviews endpoint never blocks the PDP.
 *
 * SEO:
 *   - `generateMetadata` builds the title/description + OpenGraph/Twitter
 *     cards from the product's own SEO fields.
 *   - The JSON-LD `Product` + `AggregateRating` + `Offer` block below is what
 *     makes rich results (price, availability, stars) appear in Google.
 */

interface RouteParams {
  locale: Locale;
  slug: string;
}

/** Absolute canonical URL for this PDP (used by canonical + JSON-LD + share). */
function canonicalUrl(locale: Locale, slug: string): string {
  const origin = typeof window !== "undefined" ? window.location.origin : getBackendOrigin();
  return `${origin}/${locale}/products/${slug}`;
}

export async function generateMetadata({
  params,
}: {
  params: Promise<RouteParams>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const t = await getTranslations({ locale, namespace: "pdp" });

  const product = await getProductDetailServer(slug, locale);
  if (!product) {
    return {
      title: t("errors.notFoundTitle"),
      description: t("errors.notFoundDesc"),
      robots: { index: false, follow: false },
    };
  }

  const title = product.seoTitle || `${product.name} — ${branding.name.en}`;
  const description = product.seoDescription || product.shortDescription || product.name;
  const url = canonicalUrl(locale, product.slug);
  const image = product.primaryImage || branding.logos.metadata;

  return {
    title,
    description,
    alternates: {
      canonical: url,
      languages: { en: `/en/products/${product.slug}`, ar: `/ar/products/${product.slug}`, fr: `/fr/products/${product.slug}` },
    },
    openGraph: {
      type: "website",
      locale,
      url,
      siteName: branding.name.en,
      title,
      description,
      images: [{ url: image, width: 1200, height: 1200, alt: product.name }],
    },
    twitter: {
      card: "summary_large_image",
      title,
      description,
      images: [image],
    },
    robots: { index: true, follow: true },
  };
}

export default async function Page({ params }: { params: Promise<RouteParams> }) {
  const { locale, slug } = await params;
  const t = await getTranslations({ locale, namespace: "pdp" });

  const product = await getProductDetailServer(slug, locale);
  if (!product) notFound();

  // Non-blocking: an empty rail is better than a failed page.
  const related = await getRelatedServer(product, locale);

  const url = canonicalUrl(locale, product.slug);
  const price = product.variants[0]?.price ?? 0;
  const inStock = product.stock.availability !== "out-of-stock";

  // JSON-LD structured data → Google rich results (price, availability, stars).
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Product",
    "@id": url,
    name: product.name,
    description: product.seoDescription || product.shortDescription || undefined,
    sku: product.variants[0]?.sku ?? undefined,
    brand: product.brand
      ? { "@type": "Brand", name: product.brand.name }
      : { "@type": "Brand", name: branding.name.en },
    category: product.category?.name,
    image: product.images.map((img) => img.url),
    url,
    offers: {
      "@type": "Offer",
      price: price.toFixed(2),
      priceCurrency: "SAR",
      availability: inStock
        ? "https://schema.org/InStock"
        : "https://schema.org/OutOfStock",
      itemCondition: "https://schema.org/NewCondition",
      url,
      seller: { "@type": "Organization", name: branding.name.en },
    },
    ...(product.summary.count > 0
      ? {
        aggregateRating: {
          "@type": "AggregateRating",
          ratingValue: Number(product.summary.average.toFixed(1)),
          reviewCount: product.summary.count,
          bestRating: 5,
          worstRating: 1,
        },
      }
      : {}),
  };

  return (
    <>
      <script
        type="application/ld+json"
        // The object above is built from server-fetched data only; it is safe
        // to serialize as-is (no user input reaches these fields).
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <ProductDetailPage product={product} initialRelated={related} />
    </>
  );
}
