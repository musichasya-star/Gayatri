<?php

namespace App\Services\CRM;

use App\Models\Branch;
use App\Models\Service;
use App\Support\ServiceGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ServiceCatalogueBrowser
{
    public function browse(Request $request): array
    {
        $filters = array_replace(['search' => '', 'service_group' => null, 'category' => null, 'branch_id' => null, 'active' => '1', 'kind' => null, 'readiness' => null, 'family' => null], $request->validate([
            'search' => ['nullable', 'string', 'max:150'], 'service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))],
            'category' => ['nullable', 'string', 'max:255'], 'branch_id' => ['nullable', 'integer', 'exists:branches,id'], 'active' => ['nullable', 'boolean'],
            'kind' => ['nullable', Rule::in(array_keys(Service::KINDS))], 'readiness' => ['nullable', Rule::in(array_keys(Service::READINESS))],
            'family' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1'],
        ]));
        // Grouping is a read-only projection. No parent records or variant IDs are merged.
        $all = Service::with('branch')->when($filters['service_group'], fn ($q, $group) => $q->where('service_group', $group))->orderBy('name')->orderBy('id')->get();
        $categories = $all->pluck('category')->filter()->unique()->sort()->values();
        $matching = $all->filter(function (Service $service) use ($filters) {
            $needle = mb_strtolower(trim((string) $filters['search']));

            return ($needle === '' || str_contains(mb_strtolower(implode(' ', [$service->name, $service->familyName(), $service->variant_label, $service->category, $service->catalog_code])), $needle))
                && ($filters['category'] === null || $service->category === $filters['category'])
                && ($filters['branch_id'] === null || (int) $service->branch_id === (int) $filters['branch_id'])
                && ($filters['active'] === null || $service->is_active === (bool) $filters['active'])
                && ($filters['kind'] === null || $service->catalogueKind() === $filters['kind'])
                && ($filters['readiness'] === null || $service->readiness() === $filters['readiness'])
                && ($filters['family'] === null || $service->familyName() === $filters['family']);
        });
        $rank = ['treatment' => 0, 'package' => 1, 'class' => 2, 'addon' => 3];
        $groups = $matching->groupBy(fn ($s) => json_encode([$s->service_group, $s->familyName()]))->map(function ($variants) use ($rank) {
            $first = $variants->first();
            $prices = $variants->whereNotNull('price')->pluck('price')->map(fn ($price) => (float) $price);

            return ['name' => $first->familyName(), 'group' => $first->service_group, 'key' => 'family-'.sha1($first->service_group.'|'.$first->familyName()),
                'variants' => $variants->sortBy(fn ($s) => [! $s->is_active, $s->price === null, (float) $s->price, $s->name])->values(),
                'min_price' => $prices->min(), 'max_price' => $prices->max(), 'unknown_prices' => $variants->whereNull('price')->count(),
                'ready_count' => $variants->filter(fn ($s) => $s->readiness() === 'ready')->count(),
                'admin_count' => $variants->filter(fn ($s) => $s->readiness() === 'admin')->count(),
                'incomplete_count' => $variants->filter(fn ($s) => $s->readiness() === 'incomplete')->count(),
                'active_count' => $variants->where('is_active', true)->count(), 'rank' => $variants->min(fn ($s) => $rank[$s->catalogueKind()])];
        })->sortBy(fn ($family) => [$family['rank'], mb_strtolower($family['name']), $family['group']])->values();
        $page = $request->integer('page', 1);
        $families = new LengthAwarePaginator($groups->forPage($page, 12)->values(), $groups->count(), 12, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $visible = new Collection($families->getCollection()->flatMap(fn ($family) => $family['variants'])->values()->all());
        $visible->load(['addOns.addonService', 'activeAddOns.addonService']);
        // Keep the existing view contract for consumers inspecting visible service models.
        $services = new LengthAwarePaginator($visible, $matching->count(), 12, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return compact('families', 'services', 'categories', 'filters') + ['branches' => Branch::orderBy('name')->get(), 'variantCount' => $matching->count()];
    }
}
