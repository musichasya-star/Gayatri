<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalProduct;
use App\Services\CRM\AuditLogService;
use App\Support\AdminReviewContext;
use App\Support\CatalogueSearch;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RentalProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = array_replace(['search' => '', 'category' => null, 'active' => '1', 'readiness' => null], $request->validate(['search' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'boolean'], 'readiness' => ['nullable', 'in:ready,incomplete,empty,busy'], 'page' => ['nullable', 'integer', 'min:1']]));
        $query = RentalProduct::withCount(['units', 'units as ready_units_count' => fn ($q) => $q->where('status', 'ready')])
            ->when($filters['category'], fn ($q, $value) => $q->where('category', $value))
            ->when($filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']));
        CatalogueSearch::apply($query, $filters['search'], ['name', 'sku', 'category']);
        $rows = $query->orderBy('name')->get()->when($filters['readiness'], fn ($rows, $value) => $rows->filter(fn ($product) => $product->catalogueReadiness() === $value))->values();
        $page = $request->integer('page', 1);
        $products = new LengthAwarePaginator($rows->forPage($page, 15)->values(), $rows->count(), 15, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $categories = RentalProduct::distinct()->orderBy('category')->pluck('category');

        return view('admin.rentals.products.index', compact('products', 'filters', 'categories'));
    }

    public function create()
    {
        return view('admin.rentals.products.form', ['product' => new RentalProduct(['is_active' => true, 'turnaround_days' => 1, 'deposit' => 0])]);
    }

    public function edit(RentalProduct $product)
    {
        return view('admin.rentals.products.form', compact('product'));
    }

    public function store(Request $request, AuditLogService $audit)
    {
        return $this->save($request, new RentalProduct, $audit);
    }

    public function update(Request $request, RentalProduct $product, AuditLogService $audit)
    {
        return $this->save($request, $product, $audit);
    }

    private function save(Request $request, RentalProduct $product, AuditLogService $audit)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:50', Rule::unique('rental_products')->ignore($product)],
            'name' => ['required', 'string', 'max:150'], 'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'], 'components' => ['nullable', 'string', 'max:3000'], 'terms' => ['nullable', 'string', 'max:5000'],
            'daily_rate' => ['required', 'integer', 'min:0', 'max:10000000'], 'weekly_rate' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'fortnightly_rate' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'monthly_rate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'deposit' => ['nullable', 'integer', 'min:0', 'max:100000000'], 'turnaround_days' => ['required', 'integer', 'min:0', 'max:30'],
            'is_active' => ['required', 'boolean'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'inspection_checklist_text' => ['nullable', 'string', 'max:6000'],
            'confirm_turnaround' => ['nullable', 'boolean'],
        ]);
        if ($request->exists('inspection_checklist_text')) {
            $labels = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $data['inspection_checklist_text'] ?? '')))));
            if (count($labels) > 30 || collect($labels)->contains(fn ($label) => mb_strlen($label) > 180)) {
                throw ValidationException::withMessages(['inspection_checklist_text' => 'Maksimal 30 butir pemeriksaan, setiap butir maksimal 180 karakter.']);
            }
            $data['inspection_checklist'] = $labels;
        }
        unset($data['inspection_checklist_text']);
        unset($data['photo'], $data['confirm_turnaround']);
        if (data_get($product->catalog_metadata, 'turnaround_unconfirmed') && $request->boolean('confirm_turnaround')) {
            $data['catalog_metadata'] = array_replace($product->catalog_metadata, ['turnaround_unconfirmed' => false]);
        }
        if ($request->hasFile('photo')) {
            $data['image_path'] = $request->file('photo')->store('rentals/products', 'public');
        }
        DB::transaction(function () use ($request, $product, $audit, $data) {
            $old = $product->exists ? $product->getAttributes() : [];
            $product->fill($data)->save();
            $audit->log($request->user(), 'rental.product.save', $product, $request, $old, $data, 'Katalog rental diperbarui');
        });

        return redirect(AdminReviewContext::back($request, 'rental-products'))->with('status', 'Alat rental tersimpan. Tambahkan unit fisik di menu Stok Unit.');
    }
}
