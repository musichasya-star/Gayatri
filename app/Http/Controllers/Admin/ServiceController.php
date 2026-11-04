<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Services\CRM\ServiceCatalogService;
use App\Services\CRM\ServiceCatalogueBrowser;
use App\Support\AdminReviewContext;
use App\Support\ServiceGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $serviceCatalogService) {}

    public function index(Request $request, ServiceCatalogueBrowser $catalogue): View
    {
        return view('admin.services.index', $catalogue->browse($request));
    }

    public function create(Request $request): View
    {
        $request->validate(['service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))], 'variant_of' => ['nullable', 'integer', 'exists:services,id']]);
        $source = $request->filled('variant_of') ? Service::findOrFail($request->integer('variant_of')) : null;

        return view('admin.services.form', [
            'service' => new Service(['is_active' => false, 'service_group' => $source?->service_group ?? $request->input('service_group') ?: ServiceGroup::SPA,
                'name' => $source?->familyName(), 'catalog_family' => $source?->familyName(), 'category' => $source?->category, 'branch_id' => $source?->branch_id,
                'booking_mode' => 'admin', 'catalog_metadata' => $source ? ['kind' => $source->catalogueKind(), 'package_quantity' => data_get($source->catalog_metadata, 'package_quantity', 1), 'package_unit' => data_get($source->catalog_metadata, 'package_unit', 'sesi')] : []]),
            'branches' => Branch::orderBy('name')->get(),
            'addonServices' => Service::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $service = $this->serviceCatalogService->create($request->validated(), $request->user(), $request);

        return redirect(AdminReviewContext::back($request, 'services', $request->boolean('catalogue_form') ? ['service_group' => $service->service_group] : []))->with('status', 'Layanan berhasil dibuat.');
    }

    public function edit(Service $service): View
    {
        $service->load('addOns.addonService');

        return view('admin.services.form', [
            'service' => $service,
            'branches' => Branch::orderBy('name')->get(),
            'addonServices' => Service::where('is_active', true)->whereKeyNot($service->id)->orderBy('name')->get(),
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $this->serviceCatalogService->update($service, $request->validated(), $request->user(), $request);

        return redirect(AdminReviewContext::back($request, 'services', $request->boolean('catalogue_form') ? ['service_group' => $service->service_group] : []))->with('status', 'Layanan berhasil diperbarui.');
    }

    public function archive(Request $request, Service $service): RedirectResponse
    {
        $this->serviceCatalogService->archive($service, $request->user(), $request);

        return redirect(AdminReviewContext::back($request, 'services', $request->boolean('catalogue_form') ? ['service_group' => $service->service_group] : []))->with('status', 'Layanan berhasil diarsipkan.');
    }
}
