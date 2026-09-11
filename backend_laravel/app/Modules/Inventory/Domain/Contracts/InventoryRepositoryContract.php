<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Contracts;

use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryReservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface InventoryRepositoryContract
{
    public function findByVariantId(int $variantId): ?Inventory;

    public function findReservationById(int $reservationId): ?InventoryReservation;

    public function findActiveReservationsForOrder(int $orderId): Collection;

    public function reserve(int $orderId, int $variantId, int $quantity, ?int $actorId = null, ?string $expiresAt = null): int;

    public function reserveBatch(int $orderId, array $variantIds, array $quantities, ?int $actorId = null, ?string $expiresAt = null): array;

    public function releaseReservation(int $reservationId, string $newStatus = 'released', ?int $actorId = null): void;

    public function convertReservation(int $reservationId, ?int $actorId = null): void;

    public function receiveStock(int $variantId, int $quantity, ?string $note = null, ?int $actorId = null): void;

    public function adjustStock(int $variantId, int $quantityDelta, ?string $note = null, ?int $actorId = null): void;

    public function returnStock(int $variantId, int $quantity, ?int $orderId = null, ?string $note = null, ?int $actorId = null): void;

    public function updateSettings(int $variantId, ?int $reorderPoint = null, ?int $reorderQuantity = null, ?bool $allowBackorder = null): Inventory;

    public function getLowStock(int $perPage = 15): LengthAwarePaginator;

    public function getMovements(int $variantId, int $perPage = 15): LengthAwarePaginator;
}
