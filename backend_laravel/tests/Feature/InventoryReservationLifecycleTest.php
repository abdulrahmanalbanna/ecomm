<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Exceptions\DuplicateReservationException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyConvertedException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyReleasedException;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryMovement;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryReservation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class InventoryReservationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $service;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);

        $category = Category::create([
            'slug' => 'cat-life-' . bin2hex(random_bytes(4)),
            'name' => 'Lifecycle Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-life-' . bin2hex(random_bytes(4)),
            'name'        => 'Lifecycle Product',
            'status'      => 'published',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-LIFE-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 50.00,
            'is_active'  => true,
        ]);

        $this->service->getOrCreateForVariant($this->variant->id);
    }

    public function test_reservation_creation_and_lifecycle_release(): void
    {
        // Receive 20 units
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 20
        ));

        // Reserve 5 units for order #100
        $resId = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 100
        ));

        $reservation = InventoryReservation::find($resId);
        $this->assertNotNull($reservation);
        $this->assertEquals(ReservationStatus::ACTIVE, $reservation->status);
        $this->assertEquals(5, $reservation->quantity);

        // Release reservation
        $this->service->releaseStock(new \App\Modules\Inventory\Application\DTOs\StockReleaseData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 100,
            reservationId: $resId
        ));

        $reservation->refresh();
        $this->assertEquals(ReservationStatus::RELEASED, $reservation->status);

        // Attempt double-release: must throw ReservationAlreadyReleasedException
        $this->expectException(ReservationAlreadyReleasedException::class);
        $this->service->releaseStock(new \App\Modules\Inventory\Application\DTOs\StockReleaseData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 100,
            reservationId: $resId
        ));
    }

    public function test_reservation_conversion_to_sale(): void
    {
        // Receive 20 units
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 20
        ));

        // Reserve 5 units for order #101
        $resId = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 101
        ));

        // Convert reservation on order delivery
        $this->service->deductStock(new \App\Modules\Inventory\Application\DTOs\StockDeductionData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 101,
            reservationId: $resId
        ));

        $reservation = InventoryReservation::find($resId);
        $this->assertEquals(ReservationStatus::CONVERTED, $reservation->status);

        $inventory = $this->service->getInventory($this->variant->id);
        $this->assertEquals(15, $inventory->quantity_on_hand);
        $this->assertEquals(0, $inventory->quantity_reserved);

        // Attempt double-conversion: must throw ReservationAlreadyConvertedException
        $this->expectException(ReservationAlreadyConvertedException::class);
        $this->service->deductStock(new \App\Modules\Inventory\Application\DTOs\StockDeductionData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 101,
            reservationId: $resId
        ));
    }

    public function test_idempotent_reservation_retry_returns_same_reservation_id(): void
    {
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 20
        ));

        // First reservation for order 200
        $resId1 = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 2,
            orderId: 200
        ));

        // Second reservation retry for SAME order 200 + matching quantity: returns same reservation ID idempotently
        $resId2 = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 2,
            orderId: 200
        ));

        $this->assertEquals($resId1, $resId2);

        // Verify stock was NOT reserved twice
        $inventory = $this->service->getInventory($this->variant->id);
        $this->assertEquals(2, $inventory->quantity_reserved);
        $this->assertEquals(18, $inventory->quantity_available);
    }

    public function test_conflicting_duplicate_reservation_quantity_fails(): void
    {
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 20
        ));

        // First reservation for order 200 with quantity 2
        $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 2,
            orderId: 200
        ));

        // Second reservation for order 200 with conflicting quantity 5: fails with DuplicateReservationException
        $this->expectException(DuplicateReservationException::class);
        $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 5,
            orderId: 200
        ));
    }

    public function test_movement_ledger_tracks_all_delta_and_after_fields(): void
    {
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 10
        ));

        $resId = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 3,
            orderId: 300
        ));

        $this->service->deductStock(new \App\Modules\Inventory\Application\DTOs\StockDeductionData(
            variantId: $this->variant->id,
            quantity: 3,
            orderId: 300,
            reservationId: $resId
        ));

        $movements = InventoryMovement::where('variant_id', $this->variant->id)
            // PostgreSQL timestamps may be equal for rapid writes; the sequence-backed
            // movement ID is the authoritative insertion order within this test.
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(3, $movements);

        // Movement 1: purchase
        $m1 = $movements[0];
        $this->assertEquals(10, $m1->quantity_on_hand_delta);
        $this->assertEquals(0, $m1->quantity_reserved_delta);
        $this->assertEquals(10, $m1->quantity_on_hand_after);
        $this->assertEquals(0, $m1->quantity_reserved_after);

        // Movement 2: reservation
        $m2 = $movements[1];
        $this->assertEquals(0, $m2->quantity_on_hand_delta);
        $this->assertEquals(3, $m2->quantity_reserved_delta);
        $this->assertEquals(10, $m2->quantity_on_hand_after);
        $this->assertEquals(3, $m2->quantity_reserved_after);

        // Movement 3: sale
        $m3 = $movements[2];
        $this->assertEquals(-3, $m3->quantity_on_hand_delta);
        $this->assertEquals(-3, $m3->quantity_reserved_delta);
        $this->assertEquals(7, $m3->quantity_on_hand_after);
        $this->assertEquals(0, $m3->quantity_reserved_after);
    }

    public function test_explicit_quantity_backordered_tracking(): void
    {
        // Enable backorder
        $this->service->updateSettings($this->variant->id, new \App\Modules\Inventory\Application\DTOs\InventorySettingsData(
            allowBackorder: true
        ));

        // Receive 5 units (on_hand = 5, reserved = 0, backordered = 0, available = 5)
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 5
        ));

        // Reserve 8 units for order #500 -> 5 filled from stock, 3 backordered!
        $resId = $this->service->reserveStock(new \App\Modules\Inventory\Application\DTOs\StockReservationData(
            variantId: $this->variant->id,
            quantity: 8,
            orderId: 500
        ));

        $reservation = InventoryReservation::find($resId);
        $this->assertEquals(8, $reservation->quantity);
        $this->assertEquals(3, $reservation->quantity_backordered);

        $inventory = $this->service->getInventory($this->variant->id);
        $this->assertEquals(5, $inventory->quantity_on_hand);
        $this->assertEquals(8, $inventory->quantity_reserved);
        $this->assertEquals(3, $inventory->quantity_backordered);
        $this->assertEquals(0, $inventory->quantity_available);

        // Receive 10 more units -> backorder of 3 is fulfilled!
        $this->service->receiveStock(new \App\Modules\Inventory\Application\DTOs\StockReceiveData(
            variantId: $this->variant->id,
            quantity: 10
        ));

        $inventory->refresh();
        $reservation->refresh();

        $this->assertEquals(15, $inventory->quantity_on_hand);
        $this->assertEquals(8, $inventory->quantity_reserved);
        $this->assertEquals(0, $inventory->quantity_backordered);
        $this->assertEquals(7, $inventory->quantity_available);
        $this->assertEquals(0, $reservation->quantity_backordered);
    }
}
