<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Services\CRM\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromoController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(): View
    {
        return view('admin.promos.index', [
            'promos' => Promo::latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.promos.form', ['promo' => new Promo(['is_active' => true, 'type' => 'fixed'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $promo = Promo::create($this->validatedData($request));
        $this->auditLogService->log($request->user(), 'promo.create', $promo, $request, [], $promo->only(['title', 'code', 'type', 'value', 'quota', 'is_active']), 'Promo created');

        return redirect()->route('admin.promos.index')->with('status', 'Promo berhasil dibuat.');
    }

    public function edit(Promo $promo): View
    {
        return view('admin.promos.form', compact('promo'));
    }

    public function update(Request $request, Promo $promo): RedirectResponse
    {
        $oldValues = $promo->only(['title', 'code', 'type', 'value', 'quota', 'is_active']);
        $promo->update($this->validatedData($request, $promo));
        $this->auditLogService->log($request->user(), 'promo.update', $promo, $request, $oldValues, $promo->only(['title', 'code', 'type', 'value', 'quota', 'is_active']), 'Promo updated');

        return redirect()->route('admin.promos.index')->with('status', 'Promo berhasil diperbarui.');
    }

    private function validatedData(Request $request, ?Promo $promo = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('promos', 'code')->ignore($promo)],
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => false];
    }
}
