import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/lib/i18n/request.ts");

const nextConfig: NextConfig = {
  // Allow the local network IP(s) for mobile testing in development.
  // See: https://nextjs.org/docs/app/api-reference/config/next-config-js/allowedDevOrigins
  allowedDevOrigins: ["192.168.8.127"],
  images: {
    // Primary images are local in public/images (see src/features/home/catalog.ts).
    // Remote patterns for Laravel media URLs (storage/app/public/*) and qwenlm.ai.
    remotePatterns: [
      // { protocol: "https", hostname: "image.qwenlm.ai", pathname: "/generated-images/**" },
      { protocol: "http", hostname: "localhost", pathname: "/storage/**" },
      { protocol: "https", hostname: "localhost", pathname: "/storage/**" },
      { protocol: "http", hostname: "127.0.0.1", pathname: "/storage/**" },
      { protocol: "https", hostname: "127.0.0.1", pathname: "/storage/**" },
      { protocol: "http", hostname: "192.168.8.127", pathname: "/storage/**" },
      { protocol: "https", hostname: "192.168.8.127", pathname: "/storage/**" },
    ],
    formats: ["image/avif", "image/webp"],
    minimumCacheTTL: 31536000,
  }
};

export default withNextIntl(nextConfig);
