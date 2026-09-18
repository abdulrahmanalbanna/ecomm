<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Canonical catalog media URL helper.
 *
 * Source of truth for product / category images is the Laravel backend:
 *   storage/app/public/catalog/*.png  →  {APP_URL}/storage/catalog/*.png
 * (exposed via `php artisan storage:link` → public/storage).
 *
 * The DB stores portable relative paths (`/storage/catalog/xxx.png`).
 * This helper normalizes every legacy variant found in older seeds
 * (`/images/xxx.png`, `catalog/xxx.png`, bare filenames) to an absolute
 * backend URL so the Next.js storefront never needs local /public/images.
 */
final class CatalogMedia
{
    /**
     * Directory (relative to the `public` disk) holding catalog images.
     */
    public const DIRECTORY = 'catalog';

    /**
     * Build an absolute backend URL for a stored image path.
     *
     * @param  string|null  $path  DB value, e.g. `/storage/catalog/espresso.png`
     * @return string|null         Absolute URL or null when empty.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        // Already absolute (http/https or protocol-relative) → passthrough.
        if (preg_match('#^(https?:)?//#i', $path) === 1) {
            return str_starts_with($path, '//') ? 'http:'.$path : $path;
        }

        // Legacy frontend path `/images/<file>` → backend catalog file.
        if (str_starts_with($path, '/images/')) {
            $path = '/storage/'.self::DIRECTORY.'/'.basename($path);
        } elseif (str_starts_with($path, 'images/')) {
            $path = '/storage/'.self::DIRECTORY.'/'.basename($path);
        } elseif (str_starts_with($path, self::DIRECTORY.'/')) {
            $path = '/storage/'.$path;
        } elseif (! str_starts_with($path, '/storage/')) {
            // Bare filename (`espresso.png`) or `/catalog/x.png` → normalize.
            $path = '/storage/'.self::DIRECTORY.'/'.basename($path);
        }

        $base = rtrim((string) config('app.url', ''), '/');

        return $base !== '' ? $base.$path : $path;
    }

    /**
     * Normalize a product/variant `media` JSON array, rewriting each
     * item's `url` to an absolute backend URL.
     *
     * @param  mixed  $media
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeMedia(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }

        // JSON object cast edge-case: associative → wrap single item.
        if (array_is_list($media) === false) {
            $media = [$media];
        }

        $normalized = [];
        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['url']) && is_string($item['url'])) {
                $item['url'] = self::url($item['url']);
            }
            $normalized[] = $item;
        }

        return $normalized;
    }
}
