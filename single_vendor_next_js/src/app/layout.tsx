import type { Metadata } from "next";
import "./globals.css";
import { branding } from "@/config/branding";

export const metadata: Metadata = {
  metadataBase: new URL(branding.siteUrl),
  title: { default: `${branding.name.en} — ${branding.name.ar} للمعدات التجارية`, template: `%s | ${branding.name.en}` },
  description: branding.description.en,
  icons: { icon: branding.logos.metadata },
  openGraph: { type: "website", title: `${branding.name.en} — Commercial Equipment`, description: branding.description.en, siteName: branding.name.en, images: [branding.logos.metadata] },
  twitter: { card: "summary_large_image", title: `${branding.name.en} — Commercial Equipment`, description: branding.description.en, images: [branding.logos.metadata] }
};
export default function RootLayout({children}:{children:React.ReactNode}) { return children; }
