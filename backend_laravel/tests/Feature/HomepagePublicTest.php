<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\BusinessSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * HomepagePublicTest
 *
 * Covers the public GET /api/v1/homepage endpoint end-to-end:
 *   - Row-level is_public / is_active enforcement (SettingsService + scopePublic)
 *   - Per-item is_active filtering (activeItems / flattenTicker / activeObject)
 *   - TEXT-stored JSON decoding and malformed-record isolation
 *   - UTF-8 / Arabic text preservation
 *   - Empty-array and missing-row defaults
 *   - Cache invalidation after model save
 *   - Scalar-type coercions (free_shipping_threshold, maintenance_mode)
 *   - Authorization: private settings cannot leak through the homepage route
 *
 * The `testing` PostgreSQL database is pre-seeded from database/sql/run-schema.sh.
 * Each test wraps writes in a transaction (DatabaseTransactions) and flushes the
 * settings cache in setUp() to guarantee isolation.
 */
final class HomepagePublicTest extends TestCase
{
    use DatabaseTransactions;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('settings.public.v1');
    }

    /**
     * Upsert a public, active business_settings row with a JSON value.
     *
     * @param  array<mixed>|array{}  $payload
     */
    private function seedJson(string $key, array $payload): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => $key],
            [
                'value'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'type'      => 'json',
                'is_public' => true,
                'is_active' => true,
            ]
        );
    }

    /**
     * Upsert a public, active business_settings row with a plain string value.
     */
    private function seedString(string $key, string $value): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => 'string', 'is_public' => true, 'is_active' => true]
        );
    }

    // -----------------------------------------------------------------------
    // 1. Fully populated active homepage
    // -----------------------------------------------------------------------

    /**
     * All whitelisted keys are seeded and active.
     * The response must:
     *  - return HTTP 200
     *  - contain every top-level key in the documented contract
     *  - expose non-null store, header, footer, and at least one feature/stat/ticker
     *  - strip is_active from every outgoing object/item
     */
    public function test_fully_populated_active_homepage_returns_complete_contract(): void
    {
        $this->seedString('store.name', 'تجاهيز');
        $this->seedString('store.currency', 'SAR');
        $this->seedString('store.phone', '+966500000000');

        $this->seedJson('features', [
            ['id' => 'f1', 'title' => 'استيراد مباشر', 'icon' => 'cargo', 'is_active' => true],
            ['id' => 'f2', 'title' => 'وكلاء معتمدون', 'icon' => 'shield', 'is_active' => true],
        ]);

        $this->seedJson('stats', [
            ['value' => 50, 'suffix' => '+', 'label' => 'مدينة', 'is_active' => true],
        ]);

        $this->seedJson('ticker_items', [
            ['text' => 'شحن مجاني', 'is_active' => true],
        ]);

        $this->seedJson('homepage.header', [
            'store_name_ar' => 'تجاهيز',
            'store_name_en' => 'TAGAHAYEEZ',
            'is_active'     => true,
        ]);

        $this->seedJson('homepage.footer', [
            'copyright' => '© 2026 TAGAHAYEEZ',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/homepage');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'store' => ['name', 'phone', 'logo', 'footer_logo', 'fav_icon', 'copyright_text', 'currency'],
                    'header',
                    'footer',
                    'features',
                    'stats',
                    'ticker_items',
                    'free_shipping_threshold',
                    'currency',
                    'maintenance_mode',
                ],
            ]);

        // Spot-check values
        $response
            ->assertJsonPath('data.store.name', 'تجاهيز')
            ->assertJsonPath('data.store.currency', 'SAR')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.header.store_name_ar', 'تجاهيز')
            ->assertJsonPath('data.footer.copyright', '© 2026 TAGAHAYEEZ')
            ->assertJsonPath('data.ticker_items', ['شحن مجاني'])
            ->assertJsonPath('data.features.0.id', 'f1')
            ->assertJsonPath('data.stats.0.value', 50)
            ->assertJsonPath('data.maintenance_mode', false);

        // is_active must never appear in the output
        $this->assertArrayNotHasKey('is_active', $response->json('data.header'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.footer'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.features.0'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.stats.0'));
    }

    // -----------------------------------------------------------------------
    // 2. Inactive row-level setting (is_active = false on the row itself)
    // -----------------------------------------------------------------------

    /**
     * When the features row has is_active = false at the row level (not item level),
     * the scopePublic() filter excludes it entirely → features defaults to [].
     *
     * Similarly, an is_active = false store.name row should fall through to the
     * hardcoded fallback name, not the DB value.
     */
    public function test_inactive_row_falls_back_to_defaults(): void
    {
        // Row-inactive features row
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            [
                'value'     => json_encode(
                    [['id' => 'should_not_appear', 'title' => 'Secret', 'is_active' => true]],
                    JSON_THROW_ON_ERROR
                ),
                'type'      => 'json',
                'is_public' => true,
                'is_active' => false,   // <-- row is inactive
            ]
        );

        // Row-inactive store.name — service should use its hardcoded fallback
        BusinessSetting::updateOrCreate(
            ['key' => 'store.name'],
            ['value' => 'Should Not Appear', 'type' => 'string', 'is_public' => true, 'is_active' => false]
        );

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        // features excluded at DB query level
        $this->assertSame([], $response->json('data.features'));

        // store.name falls back to the hardcoded default
        $this->assertNotSame('Should Not Appear', $response->json('data.store.name'));
        $this->assertSame('My E-Commerce Store', $response->json('data.store.name'));
    }

    // -----------------------------------------------------------------------
    // 3. Inactive individual JSON items (is_active: false inside the array)
    // -----------------------------------------------------------------------

    /**
     * When a features row is active but contains a mix of active and inactive items,
     * only active items survive; inactive items are dropped; is_active is stripped.
     */
    public function test_inactive_items_are_filtered_within_active_rows(): void
    {
        $this->seedJson('features', [
            ['id' => 'active_1', 'title' => 'نشط',     'is_active' => true],
            ['id' => 'inactive', 'title' => 'غير نشط', 'is_active' => false],
            ['id' => 'active_2', 'title' => 'نشط أيضاً', 'is_active' => true],
        ]);

        $this->seedJson('stats', [
            ['value' => 100, 'suffix' => '+', 'label' => 'مشروع', 'is_active' => true],
            ['value' => 999, 'suffix' => '-', 'label' => 'يجب إخفاؤه', 'is_active' => false],
        ]);

        $this->seedJson('ticker_items', [
            ['text' => 'رسالة نشطة', 'is_active' => true],
            ['text' => 'رسالة مخفية', 'is_active' => false],
        ]);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        // features: 2 active, 1 inactive
        $this->assertCount(2, $response->json('data.features'));
        $this->assertSame('active_1', $response->json('data.features.0.id'));
        $this->assertSame('active_2', $response->json('data.features.1.id'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.features.0'));

        // stats: 1 active, 1 inactive
        $this->assertCount(1, $response->json('data.stats'));
        $this->assertSame(100, $response->json('data.stats.0.value'));

        // ticker: 1 active string, 1 filtered
        $this->assertSame(['رسالة نشطة'], $response->json('data.ticker_items'));
    }

    // -----------------------------------------------------------------------
    // 4. Missing setting rows → defaults
    // -----------------------------------------------------------------------

    /**
     * When there are no rows for features, stats, ticker_items, header, and footer
     * (they were either never seeded or are excluded by the transaction rollback),
     * the response must still be 200 with safe empty/null defaults.
     *
     * NOTE: This test deletes the pre-seeded rows before asserting, which is safe
     * because DatabaseTransactions rolls back at the end of the test.
     */
    public function test_missing_setting_rows_produce_safe_defaults(): void
    {
        // Ensure none of the JSON keys exist for this test
        BusinessSetting::whereIn('key', [
            'features', 'stats', 'ticker_items', 'homepage.header', 'homepage.footer',
        ])->delete();

        Cache::forget('settings.public.v1');

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame([], $response->json('data.features'));
        $this->assertSame([], $response->json('data.stats'));
        $this->assertSame([], $response->json('data.ticker_items'));
        $this->assertNull($response->json('data.header'));
        $this->assertNull($response->json('data.footer'));
    }

    // -----------------------------------------------------------------------
    // 5a. Malformed JSON in a value column
    // -----------------------------------------------------------------------

    /**
     * A malformed JSON string in one setting must degrade gracefully to []:
     *  - The bad key returns [] (or null for objects).
     *  - Other correctly-encoded keys in the same response are unaffected.
     */
    public function test_malformed_json_degrades_gracefully_without_breaking_other_keys(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            ['value' => '{malformed json[[[', 'type' => 'json', 'is_public' => true, 'is_active' => true]
        );

        $this->seedJson('stats', [
            ['value' => 12, 'suffix' => '+', 'label' => 'مدن', 'is_active' => true],
        ]);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        // Malformed features → safe empty array
        $this->assertSame([], $response->json('data.features'));

        // Correctly-encoded stats survive
        $this->assertCount(1, $response->json('data.stats'));
        $this->assertSame(12, $response->json('data.stats.0.value'));
    }

    // -----------------------------------------------------------------------
    // 5b. Valid JSON but wrong shape (scalar / non-list object)
    // -----------------------------------------------------------------------

    /**
     * A value that is valid JSON but not a list (e.g. a JSON object where an array
     * is expected) must return [] rather than a 500.
     */
    public function test_valid_json_wrong_shape_returns_empty_array(): void
    {
        // Stored as a JSON object, not an array → array_is_list() returns false
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            ['value' => '{"id":"wrong_shape"}', 'type' => 'json', 'is_public' => true, 'is_active' => true]
        );

        // Stored as a JSON scalar number
        BusinessSetting::updateOrCreate(
            ['key' => 'stats'],
            ['value' => '42', 'type' => 'json', 'is_public' => true, 'is_active' => true]
        );

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame([], $response->json('data.features'));
        $this->assertSame([], $response->json('data.stats'));
    }

    // -----------------------------------------------------------------------
    // 6. Private settings cannot leak
    // -----------------------------------------------------------------------

    /**
     * A setting with is_public = false must never appear in the homepage payload,
     * even if its key matches a known section name.
     *
     * A setting with is_public = true but a key not in PUBLIC_KEYS must also be absent.
     */
    public function test_private_and_non_whitelisted_settings_are_never_exposed(): void
    {
        // is_public = false
        BusinessSetting::updateOrCreate(
            ['key' => 'financial.tax_rate'],
            ['value' => '15.00', 'type' => 'number', 'is_public' => false, 'is_active' => true]
        );

        // is_public = true but not in PUBLIC_KEYS
        BusinessSetting::updateOrCreate(
            ['key' => 'internal.secret_key'],
            ['value' => 'super_secret', 'type' => 'string', 'is_public' => true, 'is_active' => true]
        );

        // is_public = false on features → row never loaded
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            [
                'value'     => json_encode([['id' => 'leak', 'title' => 'Leak', 'is_active' => true]], JSON_THROW_ON_ERROR),
                'type'      => 'json',
                'is_public' => false,
                'is_active' => true,
            ]
        );

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('financial.tax_rate', $body);
        $this->assertStringNotContainsString('15.00', $body);
        $this->assertStringNotContainsString('super_secret', $body);
        $this->assertStringNotContainsString('internal.secret_key', $body);

        // features must be the safe default []
        $this->assertSame([], $response->json('data.features'));
    }

    // -----------------------------------------------------------------------
    // 7. Arabic text round-trip preservation
    // -----------------------------------------------------------------------

    /**
     * Arabic (RTL, multi-byte UTF-8) text stored in a TEXT column must survive
     * encode → DB → decode → HTTP response byte-for-byte.
     */
    public function test_arabic_text_is_preserved_through_encode_store_decode_http(): void
    {
        $arabicName    = 'متجر تجاهيز للمعدات التجارية';
        $arabicFeature = 'استيراد مباشر من المصانع';
        $arabicTicker  = 'شحن مجاني للطلبات فوق ١٬٥٠٠ ر.س';

        $this->seedString('store.name', $arabicName);

        $this->seedJson('features', [
            ['id' => 'ar1', 'title' => $arabicFeature, 'is_active' => true],
        ]);

        $this->seedJson('ticker_items', [
            ['text' => $arabicTicker, 'is_active' => true],
        ]);

        $this->seedJson('homepage.header', [
            'store_name_ar' => 'تجاهيز',
            'tagline_ar'    => 'معدات تجارية للمطاعم والمقاهي والمخابز',
            'is_active'     => true,
        ]);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        // Exact byte-for-byte match — no \uXXXX escape sequences
        $this->assertSame($arabicName, $response->json('data.store.name'));
        $this->assertSame($arabicFeature, $response->json('data.features.0.title'));
        $this->assertSame([$arabicTicker], $response->json('data.ticker_items'));
        $this->assertSame('تجاهيز', $response->json('data.header.store_name_ar'));
        $this->assertSame('معدات تجارية للمطاعم والمقاهي والمخابز', $response->json('data.header.tagline_ar'));
    }

    // -----------------------------------------------------------------------
    // 8. Empty arrays stored as value
    // -----------------------------------------------------------------------

    /**
     * When features and stats rows are seeded with an explicit empty JSON array [],
     * the response must return [] for those keys (no 500, no null, no missing key).
     */
    public function test_empty_json_arrays_return_empty_arrays_not_null(): void
    {
        $this->seedJson('features', []);
        $this->seedJson('stats', []);
        $this->seedJson('ticker_items', []);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame([], $response->json('data.features'));
        $this->assertSame([], $response->json('data.stats'));
        $this->assertSame([], $response->json('data.ticker_items'));

        // Keys must be present even when empty
        $this->assertArrayHasKey('features', $response->json('data'));
        $this->assertArrayHasKey('stats', $response->json('data'));
        $this->assertArrayHasKey('ticker_items', $response->json('data'));
    }

    // -----------------------------------------------------------------------
    // 9. Cache invalidation after model save
    // -----------------------------------------------------------------------

    /**
     * After a BusinessSetting row is saved, the `saved` model event must flush
     * 'settings.public.v1' so the next request reads fresh data from the DB.
     */
    public function test_cache_is_invalidated_after_model_save(): void
    {
        $this->seedString('store.name', 'Original Name');

        // Prime the cache
        $first = $this->getJson('/api/v1/homepage')->assertOk();
        $this->assertSame('Original Name', $first->json('data.store.name'));

        // Update the model — should flush the cache via the `saved` event
        BusinessSetting::where('key', 'store.name')
            ->update(['value' => 'Updated Name', 'updated_at' => now()]);

        // Eloquent mass-update does NOT fire model events; use find+save to
        // trigger the booted() hook.
        $row = BusinessSetting::where('key', 'store.name')->first();
        $this->assertNotNull($row);
        $row->value = 'Updated Name';
        $row->save(); // fires `saved` → Cache::forget

        $second = $this->getJson('/api/v1/homepage')->assertOk();
        $this->assertSame('Updated Name', $second->json('data.store.name'));
    }

    // -----------------------------------------------------------------------
    // 10. free_shipping_threshold default when row is absent
    // -----------------------------------------------------------------------

    /**
     * When no shipping.free_shipping_threshold row exists, the service must
     * return the hardcoded DEFAULT_FREE_SHIPPING_THRESHOLD (1500).
     */
    public function test_free_shipping_threshold_falls_back_to_1500_when_row_absent(): void
    {
        BusinessSetting::where('key', 'shipping.free_shipping_threshold')->delete();
        Cache::forget('settings.public.v1');

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame(1500.0, (float) $response->json('data.free_shipping_threshold'));
    }

    // -----------------------------------------------------------------------
    // 11. maintenance_mode boolean coercion from string
    // -----------------------------------------------------------------------

    /**
     * The `system.maintenance_mode` setting is stored as type=boolean and the raw
     * value is the string 'true' or 'false'. The service must return PHP bool, not
     * the raw string, in the response.
     */
    public function test_maintenance_mode_string_coerces_to_boolean(): void
    {
        // String 'true' → boolean true
        BusinessSetting::updateOrCreate(
            ['key' => 'system.maintenance_mode'],
            ['value' => 'true', 'type' => 'boolean', 'is_public' => true, 'is_active' => true]
        );
        Cache::forget('settings.public.v1');

        $on = $this->getJson('/api/v1/homepage')->assertOk();
        $this->assertTrue($on->json('data.maintenance_mode'));

        // String 'false' → boolean false
        BusinessSetting::updateOrCreate(
            ['key' => 'system.maintenance_mode'],
            ['value' => 'false', 'type' => 'boolean', 'is_public' => true, 'is_active' => true]
        );
        Cache::forget('settings.public.v1');

        $off = $this->getJson('/api/v1/homepage')->assertOk();
        $this->assertFalse($off->json('data.maintenance_mode'));
    }
}
