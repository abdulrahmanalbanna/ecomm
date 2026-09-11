<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Application\DTOs\StockReservationData;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;
    protected ProductVariant $variant;
    protected InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->adminUser = User::create([
            'email'             => 'cnc_stk_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $adminRole->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->adminUser->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $category = Category::create([
            'slug' => 'cat-cnc-' . bin2hex(random_bytes(4)),
            'name' => 'Cnc Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-cnc-' . bin2hex(random_bytes(4)),
            'name'        => 'Cnc Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-CNC-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        $this->inventoryService = app(InventoryService::class);

        // Receive 5 units
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", ['quantity' => 5]);
    }

    public function test_concurrent_reservations_serialize_and_prevent_overselling(): void
    {
        // First reservation of 3 units succeeds
        $dto1 = new StockReservationData(
            variantId: $this->variant->id,
            quantity: 3,
            createdBy: $this->adminUser->id
        );
        $reservationId = $this->inventoryService->reserveStock($dto1);
        $inventory1 = $this->inventoryService->getInventory($this->variant->id);
        $this->assertEquals(2, $inventory1->quantity_available);

        // Second reservation of 3 units should fail due to insufficient remaining available stock (2 available)
        $dto2 = new StockReservationData(
            variantId: $this->variant->id,
            quantity: 3,
            createdBy: $this->adminUser->id
        );

        $this->expectException(InsufficientInventoryException::class);
        $this->inventoryService->reserveStock($dto2);
    }
}
