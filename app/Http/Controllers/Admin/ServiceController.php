<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Services\CRM\ServiceCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $serviceCatalogService) {}

    public function index(Request $request): View
    {
        $services = Service::query()
            ->with(['branch', 'activeAddOns.addonService'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.index', [
            'services' => $services,
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.form', [
            'service' => new Service(['duration_minutes' => 60, 'is_active' => true]),
            'branches' => Branch::orderBy('name')->get(),
            'addonServices' => Service::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $this->serviceCatalogService->create($request->validated(), $request->user(), $request);

        return redirect()->route('admin.services.index')->with('status', 'Layanan berhasil dibuat.');
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

        return redirect()->route('admin.services.index')->with('status', 'Layanan berhasil diperbarui.');
    }

    public function archive(Request $request, Service $service): RedirectResponse
    {
        $this->serviceCatalogService->archive($service, $request->user(), $request);

        return redirect()->route('admin.services.index')->with('status', 'Layanan berhasil diarsipkan.');
    }
}
