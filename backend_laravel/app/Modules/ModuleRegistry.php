<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * ModuleRegistry
 *
 * Discovers and registers all application modules by convention.
 *
 * Strategy:
 *   - Maintains an explicit list of module names (prevents accidental discovery of non-modules).
 *   - For each module, attempts to load `app/Modules/{Module}/Providers/{Module}ServiceProvider.php`.
 *   - If no provider exists for a module, that module is silently skipped during this task.
 *
 * Adding a new module:
 *   1. Add the module name to the $modules array below.
 *   2. Create `app/Modules/{Module}/Providers/{Module}ServiceProvider.php`.
 *   3. That's it — no config changes needed.
 *
 * Dependency rule enforced here:
 *   - Modules do NOT reference each other's providers directly.
 *   - All cross-module wiring happens through Shared contracts or domain events.
 */
final class ModuleRegistry
{
    /**
     * The canonical list of all application modules.
     *
     * Order matters: modules listed earlier are registered first.
     * Place modules with no inter-module dependencies first.
     */
    public const MODULES = [
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

    /**
     * Return the list of discovered ServiceProvider class names.
     *
     * Only providers that physically exist are returned.
     * This allows modules to be scaffolded without a provider
     * until they need one.[]
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function discover(): array
    {
        $providers = [];

        foreach (self::MODULES as $module) {
            $providerClass = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            if (class_exists($providerClass)) {
                $providers[] = $providerClass;
            }
        }

        return $providers;
    }

    /**
     * Return all registered module names.
     *
     * @return array<int, string>
     */
    public function moduleNames(): array
    {  
        return self::MODULES;
    }
}
