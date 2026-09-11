<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Http\Controllers;

use App\Modules\Cart\Application\DTOs\AddCartItemData;
use App\Modules\Cart\Application\DTOs\ApplyCouponData;
use App\Modules\Cart\Application\DTOs\UpdateCartItemData;
use App\Modules\Cart\Application\Services\CartService;
use App\Modules\Cart\Domain\Exceptions\CartInventoryUnavailableException;
use App\Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use App\Modules\Cart\Domain\Exceptions\CartItemNotSellableException;
use App\Modules\Cart\Domain\Exceptions\InvalidCartQuantityException;
use App\Modules\Cart\Presentation\Http\Requests\AddCartItemRequest;
use App\Modules\Cart\Presentation\Http\Requests\ApplyCartCouponRequest;
use App\Modules\Cart\Presentation\Http\Requests\UpdateCartItemRequest;
use App\Modules\Cart\Presentation\Http\Resources\CartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController
{
    public function __construct(
        private readonly CartService $cartService
    ) {
    }

    /**
     * GET /api/v1/cart
     * Always returns authenticated user's persistent cart (lazy creation).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateCart($user);

        return response()->json([
            'data' => new CartResource($cart),
        ]);
    }

    /**
     * POST /api/v1/cart/items
     * Add product variant to cart (additive quantity).
     */
    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $user = $request->user();
        $dto = new AddCartItemData(
            variantId: (int) $request->input('variant_id'),
            quantity: (int) $request->input('quantity')
        );

        try {
            $cart = $this->cartService->addItem($user, $dto);
        } catch (CartItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (CartItemNotSellableException|CartInventoryUnavailableException|InvalidCartQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Item added to cart successfully',
            'data'    => new CartResource($cart),
        ], 200);
    }

    /**
     * PATCH /api/v1/cart/items/{cartItem}
     * Update quantity of existing item (absolute quantity).
     */
    public function updateItem(UpdateCartItemRequest $request, int $cartItem): JsonResponse
    {
        $user = $request->user();
        $dto = new UpdateCartItemData(
            quantity: (int) $request->input('quantity')
        );

        try {
            $cart = $this->cartService->updateItem($user, $cartItem, $dto);
        } catch (CartItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (CartItemNotSellableException|CartInventoryUnavailableException|InvalidCartQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Cart item updated successfully',
            'data'    => new CartResource($cart),
        ]);
    }

    /**
     * DELETE /api/v1/cart/items/{cartItem}
     * Remove individual cart item.
     */
    public function removeItem(Request $request, int $cartItem): JsonResponse
    {
        $user = $request->user();

        try {
            $cart = $this->cartService->removeItem($user, $cartItem);
        } catch (CartItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Item removed from cart successfully',
            'data'    => new CartResource($cart),
        ]);
    }

    /**
     * DELETE /api/v1/cart/items
     * Clear all items from cart (idempotent, no cart created if none exists).
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->clear($user);

        return response()->json([
            'message' => 'Cart cleared successfully',
            'data'    => new CartResource($cart),
        ]);
    }

    /**
     * PUT /api/v1/cart/coupon
     * Apply tentative coupon code (creates persistent cart if none exists).
     */
    public function applyCoupon(ApplyCartCouponRequest $request): JsonResponse
    {
        $user = $request->user();
        $dto = new ApplyCouponData(
            couponCode: (string) $request->input('coupon_code')
        );

        $cart = $this->cartService->applyCoupon($user, $dto);

        return response()->json([
            'message' => 'Coupon code applied successfully',
            'data'    => new CartResource($cart),
        ]);
    }

    /**
     * DELETE /api/v1/cart/coupon
     * Remove tentative coupon code (creates empty persistent cart if none exists).
     */
    public function removeCoupon(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->removeCoupon($user);

        return response()->json([
            'message' => 'Coupon code removed successfully',
            'data'    => new CartResource($cart),
        ]);
    }
}
