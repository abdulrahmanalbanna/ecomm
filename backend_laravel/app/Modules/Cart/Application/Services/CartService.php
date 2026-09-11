<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Services;

use App\Modules\Cart\Application\DTOs\AddCartItemData;
use App\Modules\Cart\Application\DTOs\ApplyCouponData;
use App\Modules\Cart\Application\DTOs\UpdateCartItemData;
use App\Modules\Cart\Domain\Exceptions\CartInventoryUnavailableException;
use App\Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use App\Modules\Cart\Domain\Exceptions\CartItemNotSellableException;
use App\Modules\Cart\Domain\Exceptions\InvalidCartQuantityException;
use App\Modules\Cart\Infrastructure\Persistence\Models\Cart;
use App\Modules\Cart\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CartService
{
    /**
     * Retrieve existing user cart without creating one.
     */
    public function getCurrentCart(User $user): ?Cart
    {
        return Cart::where('user_id', $user->id)
            ->with(['items.variant.product', 'items.variant.inventory'])
            ->first();
    }

    /**
     * Retrieve existing persistent cart or lazily create one.
     * Concurrency-safe against UNIQUE(user_id) constraint races.
     */
    public function getOrCreateCart(User $user): Cart
    {
        $cart = Cart::where('user_id', $user->id)
            ->with(['items.variant.product', 'items.variant.inventory'])
            ->first();

        if ($cart) {
            return $cart;
        }

        try {
            return DB::transaction(function () use ($user): Cart {
                $created = Cart::create([
                    'user_id'    => $user->id,
                    'expires_at' => now()->addDays((int) config('cart.expiration_days', 30)),
                ]);

                return $created->load(['items.variant.product', 'items.variant.inventory']);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'carts_user_id_key') || str_contains($e->getMessage(), 'unique constraint')) {
                return Cart::where('user_id', $user->id)
                    ->with(['items.variant.product', 'items.variant.inventory'])
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * Add a product variant item to the cart (additive quantity).
     */
    public function addItem(User $user, AddCartItemData $data): Cart
    {
        if ($data->quantity <= 0) {
            throw new InvalidCartQuantityException('Cart item quantity must be greater than zero.');
        }

        $variant = ProductVariant::with(['product', 'inventory'])->find($data->variantId);

        if (! $variant) {
            throw new CartItemNotSellableException('Product variant does not exist.');
        }

        $this->validateSellability($variant);

        $cart = $this->getOrCreateCart($user);

        $existingItem = CartItem::where('cart_id', $cart->id)
            ->where('variant_id', $variant->id)
            ->first();

        $targetQuantity = $existingItem ? ($existingItem->quantity + $data->quantity) : $data->quantity;

        $this->validateInventoryAvailability($variant, $targetQuantity);

        DB::transaction(function () use ($cart, $variant, $existingItem, $data, $targetQuantity): void {
            if ($existingItem) {
                $existingItem->quantity = $targetQuantity;
                $existingItem->save();
            } else {
                try {
                    CartItem::create([
                        'cart_id'    => $cart->id,
                        'variant_id' => $variant->id,
                        'quantity'   => $targetQuantity,
                        'added_at'   => now(),
                    ]);
                } catch (QueryException $e) {
                    if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'uq_cart_items_cart_variant')) {
                        $item = CartItem::where('cart_id', $cart->id)
                            ->where('variant_id', $variant->id)
                            ->first();

                        if ($item) {
                            $item->quantity += $data->quantity;
                            $item->save();
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            $cart->expires_at = now()->addDays((int) config('cart.expiration_days', 30));
            $cart->save();
        });

        return $cart->fresh(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Update an existing cart item's quantity (absolute quantity).
     */
    public function updateItem(User $user, int $cartItemId, UpdateCartItemData $data): Cart
    {
        if ($data->quantity <= 0) {
            throw new InvalidCartQuantityException('Cart item quantity must be greater than zero.');
        }

        $cartItem = CartItem::where('id', $cartItemId)
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->id))
            ->with(['variant.product', 'variant.inventory', 'cart'])
            ->first();

        if (! $cartItem) {
            throw new CartItemNotFoundException('Cart item not found.');
        }

        $variant = $cartItem->variant;
        if (! $variant) {
            throw new CartItemNotSellableException('Product variant not found.');
        }

        $this->validateSellability($variant);
        $this->validateInventoryAvailability($variant, $data->quantity);

        $cartItem->quantity = $data->quantity;
        $cartItem->save();

        $cart = $cartItem->cart;
        $cart->expires_at = now()->addDays((int) config('cart.expiration_days', 30));
        $cart->save();

        return $cart->fresh(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Remove an individual cart item.
     */
    public function removeItem(User $user, int $cartItemId): Cart
    {
        $cartItem = CartItem::where('id', $cartItemId)
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $cartItem) {
            throw new CartItemNotFoundException('Cart item not found.');
        }

        $cart = $cartItem->cart;
        $cartItem->delete();

        return $cart->fresh(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Clear all items from user's cart (idempotent, does not create cart if none exists).
     */
    public function clear(User $user): Cart
    {
        $cart = Cart::where('user_id', $user->id)
            ->with(['items.variant.product', 'items.variant.inventory'])
            ->first();

        if (! $cart) {
            $emptyCart = new Cart(['user_id' => $user->id]);
            $emptyCart->setRelation('items', collect());

            return $emptyCart;
        }

        CartItem::where('cart_id', $cart->id)->delete();

        return $cart->fresh(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Apply tentative coupon code (creates persistent cart if none exists).
     */
    public function applyCoupon(User $user, ApplyCouponData $data): Cart
    {
        $cart = $this->getOrCreateCart($user);
        $cart->coupon_code = strtoupper(trim($data->couponCode));
        $cart->expires_at = now()->addDays((int) config('cart.expiration_days', 30));
        $cart->save();

        return $cart->load(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Remove tentative coupon code (creates empty persistent cart if none exists).
     */
    public function removeCoupon(User $user): Cart
    {
        $cart = $this->getOrCreateCart($user);
        $cart->coupon_code = null;
        $cart->expires_at = now()->addDays((int) config('cart.expiration_days', 30));
        $cart->save();

        return $cart->load(['items.variant.product', 'items.variant.inventory']);
    }

    /**
     * Validate product variant sellability against Catalog lifecycle rules.
     */
    private function validateSellability(ProductVariant $variant): void
    {
        if (! $variant->is_active || $variant->deleted_at !== null || (float) $variant->price <= 0) {
            throw new CartItemNotSellableException('Product variant is inactive or not sellable.');
        }

        $product = $variant->product;
        if (! $product || ! $product->is_active || $product->status !== 'published' || $product->deleted_at !== null) {
            throw new CartItemNotSellableException('Product is inactive or not published.');
        }
    }

    /**
     * Perform optimistic inventory availability check.
     * Does NOT reserve inventory or mutate quantity_reserved/quantity_on_hand.
     */
    private function validateInventoryAvailability(ProductVariant $variant, int $requestedQuantity): void
    {
        $inventory = $variant->inventory;

        if (! $inventory) {
            return;
        }

        if ($inventory->allow_backorder) {
            return;
        }

        $available = $inventory->quantity_available ?? ($inventory->quantity_on_hand - $inventory->quantity_reserved);

        if ($requestedQuantity > $available) {
            throw new CartInventoryUnavailableException("Requested quantity ({$requestedQuantity}) exceeds available stock ({$available}).");
        }
    }
}
