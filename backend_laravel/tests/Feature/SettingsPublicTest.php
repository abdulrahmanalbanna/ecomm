<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Infrastructure\Persistence\Models\BusinessSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SettingsPublicTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_settings_index_returns_storefront_payload(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'store.name'],
            ['value' => 'Test Store', 'type' => 'string', 'is_public' => true, 'is_active' => true]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'ticker_items'],
            ['value' => json_encode([['text' => 'Free shipping', 'is_active' => true]]), 'type' => 'json', 'is_public' => true, 'is_active' => true]
        );

        $response = $this->getJson('/api/v1/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'store',
                    'features',
                    'stats',
                    'ticker_items',
                    'free_shipping_threshold',
                    'currency',
                    'maintenance_mode',
                ],
            ])
            ->assertJsonPath('data.store.name', 'Test Store')
            ->assertJsonPath('data.ticker_items', ['Free shipping']);
    }

    public function test_public_settings_show_single_key(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'store.currency'],
            ['value' => 'SAR', 'type' => 'string', 'is_public' => true, 'is_active' => true]
        );

        $response = $this->getJson('/api/v1/settings/store.currency');

        $response->assertStatus(200)
            ->assertJsonPath('data.key', 'store.currency')
            ->assertJsonPath('data.value', 'SAR');
    }

    public function test_public_settings_show_unknown_key_returns_404(): void
    {
        $response = $this->getJson('/api/v1/settings/does.not.exist');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Setting not found');
    }

    public function test_private_settings_are_never_exposed(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'financial.tax_rate'],
            ['value' => '15.00', 'type' => 'number', 'is_public' => false, 'is_active' => true]
        );

        $this->getJson('/api/v1/settings/financial.tax_rate')->assertStatus(404);

        $index = $this->getJson('/api/v1/settings');
        $index->assertStatus(200);
        $payload = json_encode($index->json('data'));
        $this->assertStringNotContainsString('financial.tax_rate', (string) $payload);
    }
}
