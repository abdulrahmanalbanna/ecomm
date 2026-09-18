/**
 * Backend media URL resolver.
 *
 * Single source of truth for product / category imagery is the Laravel
 * backend: `storage/app/public/catalog/*.png`, served as
 * `{BACKEND_ORIGIN}/storage/catalog/*.png` (via `php artisan storage:link`).
 *
 * The frontend no longer ships `public/images/*` — every image URL comes
 * from `CategoryResource.image_url` / `ProductPublicResource.media[].url`
 * (absolute backend URLs) or is built here as a backend fallback.
 */

const DEFAULT_BACKEND_ORIGIN = "http://localhost:8000";
const CATALOG_DIR = "catalog";

/** Backend origin derived from `NEXT_PUBLIC_API_URL` (e.g. `.../api` → host). */
export function getBackendOrigin(): string {
  const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "").replace(/\/+$/, "");
  if (!apiUrl) return DEFAULT_BACKEND_ORIGIN;
  // Strip a trailing `/api` (with optional `/v1`) to get the backend host.
  return apiUrl.replace(/\/api(\/v1)?$/, "") || DEFAULT_BACKEND_ORIGIN;
}

/** Absolute base for backend catalog images (`{origin}/storage/catalog`). */
export function getBackendStorageBase(): string {
  return `${getBackendOrigin()}/storage/${CATALOG_DIR}`;
}

/** File basename for a URL-ish path (drops query/hash). */
function basenameOf(path: string): string {
  const clean = path.split("?")[0].split("#")[0];
  const parts = clean.split("/").filter(Boolean);
  return parts[parts.length - 1] || "";
}

/**
 * Resolve any backend/legacy image reference to an absolute backend URL.
 *
 * Handles: absolute backend URLs (passthrough), legacy absolute URLs
 * containing `/images/<file>` (rewritten to backend catalog), relative
 * `/storage/...`, legacy `/images/<file>`, `catalog/<file>` and bare
 * filenames. Empty input resolves to the backend `fallbackFile`.
 */
export function resolveMediaUrl(
  path: string | null | undefined,
  fallbackFile = "hero.png",
): string {
  const storageBase = getBackendStorageBase();

  if (!path || (typeof path === "string" && path.trim() === "")) {
    return `${storageBase}/${fallbackFile}`;
  }

  const value = path.trim();

  // Absolute URL → passthrough, except legacy `/images/` payloads from
  // databases seeded before the backend move (rewrite to backend catalog).
  if (/^(https?:)?\/\//i.test(value)) {
    const withProtocol = value.startsWith("//") ? `http:${value}` : value;
    const legacyBase = basenameOf(withProtocol);
    if (withProtocol.includes("/images/") && legacyBase) {
      return `${storageBase}/${legacyBase}`;
    }
    return withProtocol;
  }

  if (value.startsWith("/storage/")) {
    return `${getBackendOrigin()}${value}`;
  }

  if (value.startsWith("/images/") || value.startsWith("images/")) {
    return `${storageBase}/${basenameOf(value)}`;
  }

  if (value.startsWith(`/${CATALOG_DIR}/`) || value.startsWith(`${CATALOG_DIR}/`)) {
    return `${storageBase}/${basenameOf(value)}`;
  }

  // Bare filename (`espresso.png`) or any other root path.
  if (!value.startsWith("/")) {
    return `${storageBase}/${basenameOf(value) || fallbackFile}`;
  }
  return `${getBackendOrigin()}${value}`;
}
