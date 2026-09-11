<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Inventory\Application\DTOs\StockBatchReservationData;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Orders\Application\DTOs\CheckoutData;
use App\Modules\Orders\Domain\Exceptions\AddressNotOwnedForCheckoutException;
use App\Modules\Orders\Domain\Exceptions\CheckoutItemNotSellableException;
use App\Modules\Orders\Domain\Exceptions\EmptyCartCheckoutException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CheckoutAction — converts the authenticated customer's cart into an order.
 *
 * Single-transaction flow (all-or-nothing):
 *  1. Lock the cart row FOR UPDATE — serializes duplicate concurrent
 *     checkouts for the same user (the second request sees an empty cart).
 *  2. Re-validate sellability against Catalog (never trust the cart or client).
 *  3. Resolve prices server-side from product_variants (Catalog is the
 *     authoritative price source; cart stores no prices).
 *  4. Compute totals with exact decimal arithmetic (bcmath on the raw NUMERIC
 *     strings — never floats). discount/shipping/tax are 0.00 (payments,
 *     shipping, and promotions modules are out of scope for Task 09).
 *  5. Insert orders + order_items with immutable JSONB snapshots.
 *  6. Reserve stock via the Inventory module's ONLY reservation mechanism:
 *     fn_reserve_inventory_batch_v2 (deadlock-safe, ascending row locks).
 *  7. Append the initial order_events row (NULL → pending).
 *  8. Clear the cart.
 *
 * PostgreSQL guards at COMMIT:
 *  - trg_orders_reconcile_subtotal: orders.subtotal = SUM(order_items.total_price)
 *  - chk_orders_total_formula: total = subtotal - discount + shipping + tax
 *  - order_items CHECK: total_price = quantity * unit_price
 * Any violation rolls the whole checkout back (including reservations).
 */
class CheckoutAction
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    public function execute(CheckoutData $data): Order
    {
        return DB::transaction(function () use ($data): Order {
            // 1. Lock the cart row: duplicate concurrent checkouts serialize here.
            $cart = DB::table('carts')
                ->where('user_id', $data->user->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                throw new EmptyCartCheckoutException();
            }

            $cartItems = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->orderBy('variant_id')
                ->get();

            if ($cartItems->isEmpty()) {
                throw new EmptyCartCheckoutException();
            }

            // 2–3. Re-validate sellability + resolve authoritative prices.
            $variantIds = $cartItems->pluck('variant_id')->all();

            /** @var array<int, ProductVariant> $variants */
            $variants = ProductVariant::with('product')
                ->withTrashed()
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');

            $lines = [];
            $subtotal = '0.00';

            foreach ($cartItems as $cartItem) {
                $variant = $variants[$cartItem->variant_id] ?? null;

                if ($variant === null) {
                    throw CheckoutItemNotSellableException::forVariant(
                        (int) $cartItem->variant_id,
                        'product no longer exists'
                    );
                }

                $this->assertSellable($variant);

                // Exact decimal string from PostgreSQL NUMERIC (raw, uncast).
                $unitPrice = (string) $variant->getRawOriginal('price');
                $quantity = (int) $cartItem->quantity;
                $lineTotal = bcmul($unitPrice, (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);

                $lines[] = [
                    'variant'   => $variant,
                    'quantity'  => $quantity,
                    'unit'      => $unitPrice,
                    'lineTotal' => $lineTotal,
                ];
            }

            // Address snapshots (ownership enforced at application layer).
            $shipping = $this->resolveOwnedAddress($data->user->id, $data->shippingAddressId);
            $billing = $data->billingAddressId !== null
                ? $this->resolveOwnedAddress($data->user->id, $data->billingAddressId)
                : $shipping;

            // 4. Server-computed totals. discount/shipping/tax = 0.00 (out of scope).
            $discount  = '0.00';
            $shipping0 = '0.00';
            $tax       = '0.00';
            $total     = bcadd(bcsub(bcadd($subtotal, $shipping0, 2), $discount, 2), $tax, 2);

            // 5. Create the order (normal table in baseline; public_id generated
            //    app-side so the response can carry it without a refresh).
            $order = Order::create([
                'public_id'        => (string) Str::uuid(),
                'user_id'          => $data->user->id,
                'status'           => OrderStatus::PENDING,
                'subtotal'         => $subtotal,
                'discount_amount'  => $discount,
                'shipping_amount'  => $shipping0,
                'tax_amount'       => $tax,
                'total_amount'     => $total,
                'currency'         => 'SAR',
                'coupon_code'      => $cart->coupon_code,
                'notes'            => $data->notes,
                'shipping_address' => $this->addressSnapshot($shipping),
                'billing_address'  => $this->addressSnapshot($billing),
                'metadata'         => (object) [],
                'ip_address'       => $data->ipAddress,
                'placed_at'        => now(),
            ]);

            foreach ($lines as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];

                OrderItem::create([
                    'order_id'         => $order->id,
                    'variant_id'       => $variant->id,
                    'product_snapshot' => $this->productSnapshot($variant),
                    'sku'              => (string) $variant->sku,
                    'name'             => (string) $variant->name,
                    'quantity'         => $line['quantity'],
                    'unit_price'       => $line['unit'],
                    'total_price'      => $line['lineTotal'],
                ]);
            }

            // 6. Reserve stock — the ONLY reservation mechanism (Inventory module
            //    → fn_reserve_inventory_batch). Throws InsufficientInventoryException
            //    (P0003) which rolls the entire checkout back.
            $this->inventoryService->reserveStockBatch(new StockBatchReservationData(
                items: array_map(
                    static fn (array $l): array => [
                        'variant_id' => (int) $l['variant']->id,
                        'quantity'   => $l['quantity'],
                    ],
                    $lines
                ),
                createdBy: $data->user->id,
                orderId: $order->id,
            ));

            // 7. Append-only lifecycle trail: initial event (NULL → pending).
            OrderEvent::create([
                'order_id'     => $order->id,
                'from_status'  => null,
                'to_status'    => OrderStatus::PENDING,
                'triggered_by' => $data->user->id,
                'note'         => 'Order placed via checkout.',
                'metadata'     => (object) [],
                'created_at'   => now(),
            ]);

            // 8. Clear the cart (items only; the cart row persists for reuse).
            DB::table('cart_items')->where('cart_id', $cart->id)->delete();

            return $order->load(['items', 'events']);
        });
    }

    /**
     * Catalog sellability — mirrors CartService rules but with Orders-specific
     * exceptions. Soft-deleted rows are loaded via withTrashed() so we can
     * report a precise reason.
     */
    private function assertSellable(ProductVariant $variant): void
    {
        if ($variant->trashed()) {
            throw CheckoutItemNotSellableException::forVariant((int) $variant->id, 'variant was removed from the catalog');
        }

        if (! $variant->is_active) {
            throw CheckoutItemNotSellableException::forVariant((int) $variant->id, 'variant is inactive');
        }

        if (bccomp((string) $variant->getRawOriginal('price'), '0.00', 2) <= 0) {
            throw CheckoutItemNotSellableException::forVariant((int) $variant->id, 'variant has no valid price');
        }

        $product = $variant->product;

        if ($product === null || $product->deleted_at !== null) {
            throw CheckoutItemNotSellableException::forVariant((int) $variant->id, 'product no longer exists');
        }

        if (! $product instanceof Product || ! $product->is_active || $product->status !== 'published') {
            throw CheckoutItemNotSellableException::forVariant((int) $variant->id, 'product is not available for purchase');
        }
    }

    private function resolveOwnedAddress(int $userId, int $addressId): Address
    {
        $address = Address::where('user_id', $userId)
            ->where('id', $addressId)
            ->first();

        if ($address === null) {
            throw new AddressNotOwnedForCheckoutException();
        }

        return $address;
    }

    /**
     * @return array<string, string|null>
     */
    private function addressSnapshot(Address $address): array
    {
        return [
            'address_id'     => (string) $address->id,
            'label'          => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone'          => $address->phone,
            'line1'          => $address->line1,
            'line2'          => $address->line2,
            'city'           => $address->city,
            'state'          => $address->state,
            'postal_code'    => $address->postal_code,
            'country_code'   => $address->country_code,
        ];
    }

    /**
     * Immutable purchase-time snapshot of product + variant.
     * Prices are captured as exact decimal strings (never floats).
     *
     * @return array<string, mixed>
     */
    private function productSnapshot(ProductVariant $variant): array
    {
        $product = $variant->product;

        return [
            'product_id'   => $product ? (string) $product->id : null,
            'product_slug' => $product?->slug,
            'product_name' => $product?->name,
            'brand'        => $product?->brand,
            'category_id'  => $product ? (string) $product->category_id : null,
            'variant_id'   => (string) $variant->id,
            'sku'          => $variant->sku,
            'name'         => $variant->name,
            'price'        => (string) $variant->getRawOriginal('price'),
            'compare_at_price' => $variant->getRawOriginal('compare_at_price') !== null
                ? (string) $variant->getRawOriginal('compare_at_price')
                : null,
            'currency'     => 'SAR',
            'attributes'   => $variant->attributes,
            'weight_grams' => $variant->weight_grams,
            'media'        => $variant->media,
            'captured_at'  => now()->toIso8601String(),
        ];
    }
}
