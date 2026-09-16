<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Services;

use App\Modules\Settings\Infrastructure\Persistence\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;

/**
 * SettingsService
 *
 * Reads public storefront settings from the `business_settings` table and
 * casts each raw string value according to its `type` column
 * (string | number | boolean | json | array).
 *
 * Source of truth for the keys below is database/sql/021_seed_data.sql:
 *   store.*, features, stats, ticker_items, homepage.header,
 *   homepage.footer, system.maintenance_mode (+ optional
 *   shipping.free_shipping_threshold with a 1500 fallback matching the
 *   legacy frontend constant).
 */
class SettingsService
{
    /**
     * Fallback used when `shipping.free_shipping_threshold` is not seeded.
     * Must stay in sync with the frontend fallback in
     * fronted_next_js/src/features/home/catalog.ts.
     */
    public const DEFAULT_FREE_SHIPPING_THRESHOLD = 1500;

    /**
     * Whitelist of keys that are safe to expose publicly.
     * Anything not listed here is never returned, even if is_public = true,
     * so internal keys stay internal by default.
     *
     * @var array<int, string>
     */
    private const PUBLIC_KEYS = [
        'store.name',
        'store.phone',
        'store.logo',
        'store.footer_logo',
        'store.fav_icon',
        'store.copyright_text',
        'store.currency',
        'system.maintenance_mode',
        'shipping.free_shipping_threshold',
        'features',
        'stats',
        'ticker_items',
        'homepage.header',
        'homepage.footer',
    ];

    /**
     * Return all whitelisted public settings, cast to PHP values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $cached */
        $cached = Cache::remember('settings.public.v1', 300, fn (): array => $this->loadFromDatabase());

        return $cached;
    }

    /**
     * Return a single public setting value, or null when the key is
     * unknown, private, inactive, or missing.
     */
    public function get(string $key): mixed
    {
        if (! in_array($key, self::PUBLIC_KEYS, true)) {
            return null;
        }

        $all = $this->all();

        return $all[$key] ?? null;
    }

    /**
     * Shape the raw key/value map into the storefront-friendly payload
     * consumed by Next.js (see fronted_next_js/src/features/home/api.ts).
     *
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    public function toPublicPayload(array $flat): array
    {
        return $this->toHomepagePayload($flat);
    }

    /**
     * Normalize the public settings into the stable homepage contract.
     *
     * `loadFromDatabase()` enforces the row-level is_public/is_active flags
     * and safely decodes each TEXT value. This method applies the second
     * activation level to JSON objects/items without allowing malformed or
     * missing settings to break the complete response.
     *
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    public function toHomepagePayload(array $flat): array
    {
        $tickerRaw = $flat['ticker_items'] ?? [];
        $ticker = $this->flattenTicker($tickerRaw);

        return [
            'store' => [
                'name'           => $flat['store.name'] ?? 'My E-Commerce Store',
                'phone'          => $flat['store.phone'] ?? null,
                'logo'           => $flat['store.logo'] ?? null,
                'footer_logo'    => $flat['store.footer_logo'] ?? null,
                'fav_icon'       => $flat['store.fav_icon'] ?? null,
                'copyright_text' => $flat['store.copyright_text'] ?? null,
                'currency'       => $flat['store.currency'] ?? 'SAR',
            ],
            'header'                   => $this->activeObject($flat['homepage.header'] ?? null),
            'footer'                   => $this->activeObject($flat['homepage.footer'] ?? null),
            'features'                 => $this->activeItems($flat['features'] ?? []),
            'stats'                    => $this->activeItems($flat['stats'] ?? []),
            'ticker_items'             => $ticker,
            'free_shipping_threshold'  => isset($flat['shipping.free_shipping_threshold'])
                ? (float) $flat['shipping.free_shipping_threshold']
                : self::DEFAULT_FREE_SHIPPING_THRESHOLD,
            'currency'                 => $flat['store.currency'] ?? 'SAR',
            'maintenance_mode'         => $this->toBool($flat['system.maintenance_mode'] ?? false),
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFromDatabase(): array
    {
        try {
            $rows = BusinessSetting::query()
                ->public()
                ->whereIn('key', self::PUBLIC_KEYS)
                ->get(['key', 'value', 'type']);
        } catch (\Throwable) {
            // Database unreachable (e.g. route smoke tests without DB):
            // return an empty map so defaults apply instead of 500ing.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            // Prefer the raw DB string so future casts/mutators can't break
            // typing (e.g. 'false' decoded to bool false by an array cast).
            $raw = $row->getRawOriginal('value') ?? $row->getAttribute('value');
            $out[$row->key] = $this->cast($raw, (string) $row->type);
        }

        return $out;
    }

    private function cast(mixed $value, string $type): mixed
    {
        // Already-decoded values (e.g. cached payloads, test doubles, or a
        // DB driver returning native types) pass through sensibly.
        if (is_array($value)) {
            return $value;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return match ($type) {
                'number'  => $value + 0,
                'boolean' => $value,
                'json', 'array' => (array) $value,
                default   => (string) $value,
            };
        }

        $value = $value === null ? null : (string) $value;

        return match ($type) {
            'number'  => is_numeric($value) ? $value + 0 : 0,
            'boolean' => in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            'json', 'array' => $this->decodeJson((string) $value),
            default   => $value,
        };
    }

    private function decodeJson(string $value): mixed
    {
        if ($value === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Seed rows store ticker items as [{text, is_active}] — flatten to
     * plain strings and drop inactive entries for the storefront ticker.
     *
     * @param  mixed  $raw
     * @return array<int, string>
     */
    private function flattenTicker(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = $item;
                continue;
            }
            if (is_array($item) && isset($item['text']) && $this->isActive($item['is_active'] ?? true)) {
                $out[] = (string) $item['text'];
            }
        }

        return array_values($out);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeObject(mixed $raw): ?array
    {
        if (! is_array($raw) || ! $this->isActive($raw['is_active'] ?? true)) {
            return null;
        }

        unset($raw['is_active']);

        return $raw;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeItems(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (! is_array($item) || ! $this->isActive($item['is_active'] ?? true)) {
                continue;
            }

            unset($item['is_active']);
            $items[] = $item;
        }

        return $items;
    }

    private function isActive(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
