<?php

declare(strict_types=1);

namespace App\Shared\Application\DTOs;

/**
 * PaginatedResult
 *
 * A generic, immutable DTO representing a paginated collection of items.
 *
 * This is a cross-cutting concern used by any module returning paginated data.
 * Place module-specific result DTOs inside the module's own Application/DTOs/ directory.
 *
 * Design decisions:
 *   - readonly: enforces immutability; once constructed, data cannot be mutated.
 *   - Generic: $items is typed as array to remain module-agnostic.
 *   - No Eloquent dependency: this DTO can be used in pure Domain/Application tests.
 *
 * Usage in a module's Application Service:
 *   return new PaginatedResult(
 *       items: $products,      // array of Product domain entities or DTOs
 *       total: 150,
 *       perPage: 15,
 *       currentPage: 2,
 *       lastPage: 10,
 *   );
 *
 * Usage in a module's API Resource / Controller:
 *   $result = $query->handle();
 *   return response()->json([
 *       'data' => $result->items,
 *       'meta' => $result->meta(),
 *   ]);
 */
final readonly class PaginatedResult
{
    public function __construct(
        /** @var array<int, mixed> */
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}

    /**
     * Return pagination metadata as an array.
     *
     * Suitable for embedding in an API response `meta` key.
     *
     * @return array<string, int>
     */
    public function meta(): array
    {
        return [
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage,
        ];
    }

    /**
     * Indicates whether there is another page after this one.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }
}
