<?php

namespace App\Http\Controllers;

use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\RentalSettings;
use App\Services\Web\PublicCatalogue;
use App\Support\CatalogueSearch;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class CatalogueController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['type' => ['nullable', Rule::in(['services', 'rental', 'retail', 'laundry'])],
            'group' => ['nullable', Rule::in(['spa', 'lactation'])], 'category' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(array_keys(Service::KINDS))], 'readiness' => ['nullable', Rule::in(['ready', 'admin'])], 'selected_service' => ['nullable', 'integer', 'min:1']]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $type = $filters['type'] ?? 'services';
        $all = PublicCatalogue::services()->when($filters['group'] ?? null, fn ($q, $group) => $q->where('service_group', $group))->orderBy('name')->get();
        $categories = $all->pluck('category')->filter()->unique()->sort()->values();
        if (isset($filters['category']) && ! $categories->contains($filters['category'])) {
            unset($filters['category']);
        }
        if (isset($filters['search'])) {
            $filters['search'] = trim($filters['search']);
        }
        $services = $all->filter(fn ($s) => (empty($filters['category']) || $s->category === $filters['category'])
            && (empty($filters['kind']) || $s->catalogueKind() === $filters['kind'])
            && (empty($filters['readiness']) || PublicCatalogue::ready($s) === ($filters['readiness'] === 'ready')));
        $families = $services->groupBy(fn ($s) => json_encode([$s->service_group, $s->familyName()]))
            ->when($filters['search'] ?? null, fn ($groups, $search) => $groups->filter(fn ($rows) => str_contains(mb_strtolower($rows->first()->familyName().' '.$rows->pluck('name')->implode(' ').' '.$rows->pluck('variant_label')->implode(' ').' '.$rows->pluck('catalog_code')->implode(' ').' '.$rows->first()->category), mb_strtolower($search))));
        $rank = ['treatment' => 0, 'class' => 1, 'package' => 2, 'addon' => 3];
        $families = $families->map(fn ($rows) => $rows->sortBy(fn ($s) => [! PublicCatalogue::ready($s), $s->price === null, (float) $s->price, $s->id])->values())
            ->sortBy(fn ($rows) => [$rank[$rows->first()->catalogueKind()], mb_strtolower($rows->first()->familyName())])->values();
        $page = $request->integer('page', 1);
        $paginated = new LengthAwarePaginator($families->forPage($page, 12), $families->count(), 12, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $search = $filters['search'] ?? null;
        $rentals = $type === 'rental' ? CatalogueSearch::apply(RentalProduct::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%']), $search, ['name', 'category'])->withCount('units')->orderBy('name')->paginate(12)->withQueryString() : collect();
        $retail = $type === 'retail' ? CatalogueSearch::apply(RetailProduct::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%']), $search, ['name', 'category'])->orderBy('name')->paginate(12)->withQueryString() : collect();
        $laundry = $type === 'laundry' ? CatalogueSearch::apply(LaundryTariff::where('is_active', true), $search, ['name'])->orderBy('name')->get() : collect();
        $outlet = app(LaundrySettings::class)->get();
        $laundryOpen = app(LaundryPublicSettings::class)->get()['enabled'] && $outlet['accepting_orders'] && trim($outlet['address']) && trim($outlet['whatsapp_number']);

        return view('catalogue.index', ['type' => $type, 'families' => $paginated, 'rentals' => $rentals, 'retail' => $retail, 'laundry' => $laundry,
            'filters' => $filters, 'categories' => $categories, 'laundryOpen' => $laundryOpen, 'rentalEnabled' => app(RentalSettings::class)->get()['enabled'],
            'contact' => app(PublicCatalogue::class)->contact()['phone']]);
    }

    public static function inquiry(string $name): string
    {
        $url = app(PublicCatalogue::class)->contact()['whatsapp'];

        return $url ? $url.'?text='.rawurlencode('Halo admin Gayatri, saya ingin konfirmasi pilihan '.$name.'. Mohon informasi rincian dan ketersediaannya.')
            : route('landing.show').'#contact';
    }
}
