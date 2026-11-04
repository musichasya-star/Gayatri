<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalOrder;
use App\Models\RentalTransportTask;
use App\Models\User;
use App\Services\CRM\RentalTransportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class RentalTransportController extends Controller
{
    public function index(Request $request, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(array_keys(RentalTransportTask::STATUSES))],
            'staff' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $date = $filters['date'] ?? CarbonImmutable::now(RentalTransportTask::TIMEZONE)->toDateString();
        $start = CarbonImmutable::parse($date, RentalTransportTask::TIMEZONE)->setTimezone(config('app.timezone'));
        $end = $start->addDay();
        $query = RentalTransportTask::with(['order.customer', 'assignedUser'])
            ->when($request->filled('staff'), fn ($q) => $q->where('assigned_user_id', $request->integer('staff')));
        // All outstanding overdue tasks for the selected staff, regardless of selected date/status.
        $overdueCount = (clone $query)->whereIn('status', RentalTransportTask::ACTIVE_STATUSES)->where('scheduled_end', '<', now())->count();
        $tasks = $query->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('scheduled_start')->orderBy('id')->paginate(25)->withQueryString();
        $staff = User::whereIn('role', RentalTransportTask::STAFF_ROLES)->orderBy('name')->get();

        return view('admin.rentals.transport.index', ['tasks' => $tasks, 'date' => $date, 'staff' => $staff, 'overdueCount' => $overdueCount, 'alerts' => $service->alerts()]);
    }

    public function create(Request $request, RentalOrder $order, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(RentalTransportTask::TYPES))]]);
        abort_unless($service->supports($order, $data['type']) && in_array($order->status, ['reserved', 'rented', 'inspection'], true), 422, 'Pesanan belum dapat dijadwalkan untuk jenis angkutan ini.');
        $task = RentalTransportTask::where('rental_order_id', $order->id)->where('type', $data['type'])->first();
        if ($task) {
            return redirect()->route('admin.rentals.transport.edit', [$order, $task]);
        }
        $task = new RentalTransportTask(['type' => $data['type'], 'status' => 'scheduled', 'version' => 0]);

        return $this->form($order, $task, $service);
    }

    public function store(Request $request, RentalOrder $order, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $task = $service->schedule($order, $request->all(), $request->user(), $request);

        return redirect()->route('admin.rentals.transport.edit', [$order, $task])->with('status', 'Jadwal angkutan tersimpan.');
    }

    public function edit(Request $request, RentalOrder $order, RentalTransportTask $task, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $this->scopeTask($order, $task);

        return $this->form($order, $task, $service);
    }

    public function update(Request $request, RentalOrder $order, RentalTransportTask $task, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $this->scopeTask($order, $task);
        $service->update($order, $task, $request->all(), $request->user(), $request);

        return redirect()->route('admin.rentals.transport.edit', [$order, $task])->with('status', 'Jadwal angkutan diperbarui.');
    }

    public function action(Request $request, RentalOrder $order, RentalTransportTask $task, RentalTransportService $service)
    {
        $this->authorizeAccess($request);
        $this->scopeTask($order, $task);
        $data = $request->validate(['action' => ['required', 'in:start,complete,cancel']]);
        match ($data['action']) {
            'start' => $service->start($order, $task, $request->all(), $request->user(), $request),
            'complete' => $service->complete($order, $task, $request->all(), $request->user(), $request),
            'cancel' => $service->cancel($order, $task, $request->all(), $request->user(), $request),
        };

        return redirect()->route('admin.rentals.transport.edit', [$order, $task])->with('status', 'Status angkutan diperbarui.');
    }

    public function photo(Request $request, RentalOrder $order, RentalTransportTask $task)
    {
        $this->authorizeAccess($request);
        $this->scopeTask($order, $task);
        abort_unless($task->proof_photo_path && Storage::disk('local')->exists($task->proof_photo_path), 404);

        return Storage::disk('local')->response($task->proof_photo_path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function form(RentalOrder $order, RentalTransportTask $task, RentalTransportService $service)
    {
        $order->loadMissing('customer');
        $task->loadMissing('assignedUser');

        return view('admin.rentals.transport.form', [
            'order' => $order, 'task' => $task,
            'staff' => User::where('status', 'active')->whereIn('role', RentalTransportTask::STAFF_ROLES)->orderBy('name')->get(),
            'canSchedule' => $service->supports($order, $task->type) && $task->status !== 'completed' && (
                in_array($order->status, ['reserved', 'rented', 'inspection'], true)
                || ($order->status === 'completed' && $task->exists && $task->type === 'collection' && $task->isActive())
            ),
            'alerts' => $service->alerts($order),
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->status === 'active' && $request->user()->hasRole(RentalTransportTask::STAFF_ROLES), 403);
    }

    private function scopeTask(RentalOrder $order, RentalTransportTask $task): void
    {
        abort_unless((int) $task->rental_order_id === (int) $order->id, 404);
    }
}
