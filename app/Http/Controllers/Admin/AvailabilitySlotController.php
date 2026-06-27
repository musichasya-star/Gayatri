<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AvailabilitySlot;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Therapist;
use App\Services\CRM\AuditLogService;
use App\Support\AvailabilitySlotStatus;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AvailabilitySlotController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(Request $request): View
    {
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $slots = AvailabilitySlot::query()
            ->with(['branch', 'service', 'therapist'])
            ->whereDate('slot_date', $date)
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('service_id'), fn ($query) => $query->where('service_id', $request->integer('service_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->paginate(20)
            ->withQueryString();

        return view('admin.availability.index', $this->sharedData() + [
            'slots' => $slots,
            'date' => $date,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $slot = AvailabilitySlot::create($data + ['booked_count' => 0]);
        $this->auditLogService->log($request->user(), 'availability.create', $slot, $request, [], $slot->toArray(), 'Availability slot created');

        return back()->with('status', 'Slot jadwal berhasil dibuat.');
    }

    public function update(Request $request, AvailabilitySlot $slot): RedirectResponse
    {
        $data = $this->validatedData($request);
        $old = $slot->toArray();
        $slot->update($data);
        $this->syncFullStatus($slot->refresh());
        $this->auditLogService->log($request->user(), 'availability.update', $slot, $request, $old, $slot->toArray(), 'Availability slot updated');

        return back()->with('status', 'Slot jadwal berhasil diperbarui.');
    }

    public function block(Request $request, AvailabilitySlot $slot): RedirectResponse
    {
        $old = $slot->toArray();
        $slot->update(['status' => AvailabilitySlotStatus::BLOCKED]);
        $this->auditLogService->log($request->user(), 'availability.block', $slot, $request, $old, $slot->toArray(), 'Availability slot blocked');

        return back()->with('status', 'Slot berhasil diblokir.');
    }

    public function bulkGenerate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'therapist_id' => ['nullable', 'exists:therapists,id'],
            'slot_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'interval_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $date = Carbon::parse($data['slot_date'])->toDateString();
        $start = Carbon::createFromFormat('H:i', $data['start_time']);
        $end = Carbon::createFromFormat('H:i', $data['end_time']);
        $created = 0;

        for ($cursor = $start->copy(); $cursor->lt($end); $cursor->addMinutes((int) $data['interval_minutes'])) {
            $slotEnd = $cursor->copy()->addMinutes((int) $data['interval_minutes']);
            if ($slotEnd->gt($end)) {
                break;
            }

            $slot = AvailabilitySlot::firstOrCreate([
                'branch_id' => $data['branch_id'] ?? null,
                'service_id' => $data['service_id'],
                'therapist_id' => $data['therapist_id'] ?? null,
                'slot_date' => $date,
                'start_time' => $cursor->format('H:i:s'),
            ], [
                'end_time' => $slotEnd->format('H:i:s'),
                'capacity' => $data['capacity'],
                'booked_count' => 0,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ]);

            $created += $slot->wasRecentlyCreated ? 1 : 0;
        }

        $this->auditLogService->log($request->user(), 'availability.bulk_generate', null, $request, [], $data + ['created' => $created], 'Availability slots bulk generated');

        return back()->with('status', "Bulk generate selesai. {$created} slot baru dibuat.");
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'therapist_id' => ['nullable', 'exists:therapists,id'],
            'slot_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'status' => ['required', Rule::in(AvailabilitySlotStatus::all())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function sharedData(): array
    {
        return [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
            'services' => Service::where('is_active', true)->orderBy('name')->get(),
            'therapists' => Therapist::where('status', 'active')->orderBy('name')->get(),
            'statuses' => AvailabilitySlotStatus::all(),
        ];
    }

    private function syncFullStatus(AvailabilitySlot $slot): void
    {
        if ($slot->status !== AvailabilitySlotStatus::BLOCKED && $slot->booked_count >= $slot->capacity) {
            $slot->update(['status' => AvailabilitySlotStatus::FULL]);
        }
    }
}
