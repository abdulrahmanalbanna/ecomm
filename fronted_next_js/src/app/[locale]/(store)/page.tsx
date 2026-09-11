import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { HomePage } from "@/features/home/components/HomePage";
import type { Locale } from "@/types/locale";
import { branding } from "@/config/branding";

export async function generateMetadata({ params }: { params: Promise<{ locale: Locale }> }): Promise<Metadata> {
  const { locale } = await params;
  setRequestLocale(locale);
  const t = await getTranslations({ locale, namespace: "home" });
  const title = locale === "ar" ? `${branding.name.ar} — المعدات التجارية` : locale === "fr" ? `${branding.name.en} — Équipements professionnels` : `${branding.name.en} — Commercial Equipment`;
  const description = t("brandTagline");
  return {
    title,
    description,
    alternates: {
      languages: { en: "/en", ar: "/ar", fr: "/fr" },
    },
    icons: { icon: branding.logos.metadata },
    openGraph: { title, description, type: "website", locale, siteName: branding.name.en, images: [branding.logos.metadata] },
    twitter: { card: "summary_large_image", title, description, images: [branding.logos.metadata] },
  };
}

export default async function Page({ params }: { params: Promise<{ locale: Locale }> }) {
  const { locale } = await params;
  setRequestLocale(locale);
  return <HomePage />;
}
