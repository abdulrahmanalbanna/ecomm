import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/lib/i18n/request.ts");

const nextConfig: NextConfig = {
  // Allow the local network IP(s) for mobile testing in development.
  // See: https://nextjs.org/docs/app/api-reference/config/next-config-js/allowedDevOrigins
  allowedDevOrigins: ["192.168.8.127"],
  images: {
    // Primary images are local in public/images (see src/features/home/catalog.ts).
    // Remote pattern kept as fallback; qwenlm.ai upstream is very slow (~14s),
    // which caused `upstream image response timed out` 500s from the optimizer.
    remotePatterns: [
      { protocol: "https", hostname: "image.qwenlm.ai", pathname: "/generated-images/**" }
    ],
    formats: ["image/avif", "image/webp"],
    minimumCacheTTL: 31536000,
  }
};

export default withNextIntl(nextConfig);
