<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogQueryIndexTest extends TestCase
{
    use DatabaseTransactions;

    public function test_category_and_product_queries_generate_valid_explain_plans(): void
    {
        $category = Category::create([
            'slug' => 'query-cat-' . bin2hex(random_bytes(4)),
            'name' => 'Query Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'query-prod-' . bin2hex(random_bytes(4)),
            'name'        => 'Query Product',
            'status'      => 'draft',
            'is_active'   => true,
            'is_featured' => true,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'QUERY-SKU-01',
            'price'      => 10.00,
            'is_active'  => true,
        ]);

        $product->status = 'published';
        $product->save();

        // 1. Featured product query plan
        $explainFeatured = DB::select("EXPLAIN SELECT * FROM products WHERE is_featured = TRUE AND is_active = TRUE AND status = 'published' AND deleted_at IS NULL");
        $this->assertNotEmpty($explainFeatured);

        // 2. Products by category query plan
        $explainCategory = DB::select("EXPLAIN SELECT * FROM products WHERE category_id = {$category->id}");
        $this->assertNotEmpty($explainCategory);

        // 3. Variant by SKU lookup
        $explainSku = DB::select("EXPLAIN SELECT * FROM product_variants WHERE sku = 'QUERY-SKU-01'");
        $this->assertNotEmpty($explainSku);

        // 4. Category ltree subtree query
        $explainSubtree = DB::select("EXPLAIN SELECT * FROM categories WHERE path <@ 'query_cat'::ltree");
        $this->assertNotEmpty($explainSubtree);
    }
}
