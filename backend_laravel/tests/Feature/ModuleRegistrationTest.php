<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * ModuleRegistrationTest
 *
 * Verifies that the ModuleRegistry correctly reports all expected modules
 * and that the module registration system is structurally sound.
 *
 * No database connection required.
 */
final class ModuleRegistrationTest extends TestCase
{
    /**
     * The ModuleRegistry must report exactly the expected 13 modules.
     */
    public function test_module_registry_reports_all_twelve_modules(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $moduleNames = $registry->moduleNames();

        $this->assertCount(13, $moduleNames);
    }

    /**
     * All expected module names must be present in the registry.
     *
     * This guards against accidental removal of a module from the list.
     */
    public function test_all_expected_module_names_are_registered(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $expected = [
            'Settings',
            'Identity',
            'Customer',
            'Catalog',
            'Inventory',
            'Cart',
            'Orders',
            'Shipping',
            'Payments',
            'Promotions',
            'Reviews',
            'Notifications',
            'Audit',
        ];

        foreach ($expected as $moduleName) {
            $this->assertContains(
                $moduleName,
                $registry->moduleNames(),
                "Module '{$moduleName}' is missing from the ModuleRegistry."
            );
        }
    }

    /**
     * The discover() method must return only class strings that exist.
     *
     * This prevents the registry from returning providers for classes
     * that haven't been created yet, which would cause runtime errors.
     */
    public function test_discovered_providers_all_exist_as_classes(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $providers = $registry->discover();

        foreach ($providers as $providerClass) {
            $this->assertTrue(
                class_exists($providerClass),
                "Provider class '{$providerClass}' was discovered but does not exist."
            );
        }
    }

    /**
     * The Identity module must be discovered because its ServiceProvider exists.
     *
     * This is the "golden path" test — Identity is the only fully-wired module in Task 01.
     */
    public function test_identity_module_provider_is_discovered(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $providers = $registry->discover();

        $this->assertContains(
            \App\Modules\Identity\Providers\IdentityServiceProvider::class,
            $providers,
            'IdentityServiceProvider was not discovered. Check that the class exists and is loadable.'
        );
    }
}
