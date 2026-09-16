<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\BusinessSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HomepagePublicTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('settings.public.v1');
    }

    public function test_homepage_returns_normalized_active_public_content(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'store.name'],
            ['value' => 'متجر تجاهيز', 'type' => 'string', 'is_public' => true, 'is_active' => true]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            [
                'value' => json_encode([
                    ['id' => 'active', 'title' => 'نشط', 'is_active' => true],
                    ['id' => 'inactive', 'title' => 'غير نشط', 'is_active' => false],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'type' => 'json',
                'is_public' => true,
                'is_active' => true,
            ]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'ticker_items'],
            [
                'value' => json_encode([
                    ['text' => 'شحن مجاني', 'is_active' => true],
                    ['text' => 'عرض منتهي', 'is_active' => false],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'type' => 'json',
                'is_public' => true,
                'is_active' => true,
            ]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'homepage.header'],
            [
                'value' => json_encode([
                    'store_name_ar' => 'تجاهيز',
                    'is_active' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'type' => 'json',
                'is_public' => true,
                'is_active' => true,
            ]
        );

        $response = $this->getJson('/api/v1/homepage');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'store',
                    'header',
                    'footer',
                    'features',
                    'stats',
                    'ticker_items',
                    'free_shipping_threshold',
                    'currency',
                    'maintenance_mode',
                ],
            ])
            ->assertJsonPath('data.store.name', 'متجر تجاهيز')
            ->assertJsonPath('data.features.0.id', 'active')
            ->assertJsonPath('data.ticker_items', ['شحن مجاني'])
            ->assertJsonPath('data.header.store_name_ar', 'تجاهيز');

        $this->assertCount(1, $response->json('data.features'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.features.0'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.header'));
    }

    public function test_inactive_or_private_settings_are_not_exposed(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'store.name'],
            ['value' => 'Inactive Store', 'type' => 'string', 'is_public' => true, 'is_active' => false]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            [
                'value' => json_encode([['id' => 'private', 'title' => 'Private', 'is_active' => true]], JSON_THROW_ON_ERROR),
                'type' => 'json',
                'is_public' => false,
                'is_active' => true,
            ]
        );

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertNotSame('Inactive Store', $response->json('data.store.name'));
        $this->assertSame([], $response->json('data.features'));
    }

    public function test_malformed_json_isolated_to_the_bad_setting(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'features'],
            ['value' => '{malformed', 'type' => 'json', 'is_public' => true, 'is_active' => true]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'stats'],
            [
                'value' => json_encode([['value' => 12, 'suffix' => '+', 'label' => 'مدن', 'is_active' => true]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'type' => 'json',
                'is_public' => true,
                'is_active' => true,
            ]
        );

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame([], $response->json('data.features'));
        $this->assertSame(12, $response->json('data.stats.0.value'));
    }
}
