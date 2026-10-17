<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->per_page ?? 12;

        $products = Product::active()
            ->ordered()
            ->paginate($perPage);

        return $this->paginatedResponse($products, 'Products fetched successfully');
    }

    public function show($slug)
    {
        $product = Product::active()->where('slug', $slug)->firstOrFail();

        return $this->successResponse($product, 'Product fetched successfully');
    }
}
