<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RetailProduct;
use App\Services\CRM\AuditLogService;
use App\Support\AdminReviewContext;
use App\Support\CatalogueSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RetailProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = array_replace(['search' => '', 'category' => null, 'active' => '1'], $request->validate(['search' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:150'], 'active' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]));
        $query = RetailProduct::query()->when($filters['category'], fn ($q, $value) => $q->where('category', $value))->when($filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']));
        CatalogueSearch::apply($query, $filters['search'], ['name', 'sku', 'category']);
        $products = $query->orderBy('name')->paginate(15)->withQueryString();
        $categories = RetailProduct::distinct()->orderBy('category')->pluck('category');

        return view('admin.retail.index', compact('products', 'filters', 'categories'));
    }

    public function form(?RetailProduct $product = null)
    {
        return view('admin.retail.form', ['product' => $product ?? new RetailProduct(['is_active' => true, 'category' => 'Produk Pendukung'])]);
    }

    public function save(Request $request, AuditLogService $audit, ?RetailProduct $product = null)
    {
        $product ??= new RetailProduct;
        $data = $request->validate(['sku' => ['required', 'string', 'max:60', Rule::unique('retail_products')->ignore($product)], 'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:150'], 'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'compare_at_price' => ['nullable', 'integer', 'gt:price', 'max:100000000'], 'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        unset($data['photo']);
        if ($request->hasFile('photo')) {
            $data['image_path'] = $request->file('photo')->store('retail/products', 'public');
        }
        $old = $product->getOriginal();
        $product->fill($data)->save();
        $audit->log($request->user(), 'retail.product.save', $product, $request, $old, $product->fresh()->toArray(), 'Katalog produk pendukung diperbarui; pemesanan tetap dikonfirmasi admin.');

        return redirect(AdminReviewContext::back($request, 'retail'))->with('status', 'Produk pendukung tersimpan.');
    }
}
