<?php

namespace App\Services\CRM;

use App\Models\RentalOrder;
use App\Models\RentalTransportTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RentalTransportService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function schedule(RentalOrder $order, array $data, User $actor, ?Request $request = null): RentalTransportTask
    {
        return $this->saveSchedule($order, null, $data, $actor, $request);
    }

    public function update(RentalOrder $order, RentalTransportTask $task, array $data, User $actor, ?Request $request = null): RentalTransportTask
    {
        return $this->saveSchedule($order, $task, $data, $actor, $request);
    }

    private function saveSchedule(RentalOrder $order, ?RentalTransportTask $task, array $data, User $actor, ?Request $request): RentalTransportTask
    {
        $this->authorize($actor);
        $data = Validator::make($data, [
            'type' => [$task ? 'sometimes' : 'required', Rule::in(array_keys(RentalTransportTask::TYPES))],
            'assigned_user_id' => ['required', 'integer'],
            'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i'],
            'scheduled_end' => ['required', 'date_format:Y-m-d\TH:i', 'after:scheduled_start'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'version' => ['required', 'integer', $task ? 'min:1' : 'in:0'],
            'entry_key' => ['required', 'uuid'],
        ])->validate();
        $data['notes'] = trim($data['notes'] ?? '');
        $hash = $this->fingerprint($task ? 'update' : 'schedule', $data);

        return DB::transaction(function () use ($order, $task, $data, $actor, $request, $hash) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = $task
                ? $this->lockedTask($order, $task)
                : RentalTransportTask::where('rental_order_id', $order->id)->where('type', $data['type'])->lockForUpdate()->first();
            if ($current && $this->replayed($current, $data, $hash)) {
                return $current;
            }
            if (! $task && $current) {
                $this->invalid('type', 'Tugas jenis ini sudah ada. Buka jadwal tersebut untuk mengubah atau menjadwalkan ulang.');
            }
            if ($current) {
                $this->checkVersion($current, $data);
                if ($current->status === 'completed') {
                    $this->invalid('status', 'Tugas selesai tidak dapat diubah.');
                }
                if (isset($data['type']) && $data['type'] !== $current->type) {
                    $this->invalid('type', 'Jenis tugas tidak dapat diubah.');
                }
            }
            $type = $current?->type ?? $data['type'];
            // Core inspection can finish before pickup proof is reconciled. Only an
            // existing active pickup may refresh its contract snapshot.
            $allowCompleted = $current && $current->type === 'collection' && $current->isActive();
            $this->assertOrder($order, $type, $allowCompleted);
            [$start, $end] = $this->parseWindow($data);
            $this->assertWindow($order, $type, $start, $end);
            // A stable row exists even when a staff member has no tasks yet.
            // Every allocation locks it before testing half-open [start, end) windows.
            $this->lockStaff((int) $data['assigned_user_id']);
            $this->assertAvailable((int) $data['assigned_user_id'], $start, $end, $current?->id);
            $before = $current?->only(['status', 'version', 'scheduled_start', 'scheduled_end', 'assigned_user_id']) ?? [];
            $values = [
                'assigned_user_id' => (int) $data['assigned_user_id'],
                'scheduled_start' => $start, 'scheduled_end' => $end,
                'schedule_version' => (int) $order->schedule_version,
                'notes' => $data['notes'], 'updated_by' => $actor->id,
            ];
            if ($current) {
                if ($current->status === 'cancelled') {
                    $values += ['status' => 'scheduled', 'cancelled_at' => null, 'cancellation_notes' => null, 'started_at' => null];
                }
                $current->fill($values);
            } else {
                $current = new RentalTransportTask($values + [
                    'rental_order_id' => $order->id, 'type' => $type, 'status' => 'scheduled',
                    'created_by' => $actor->id, 'version' => 0, 'mutation_receipts' => [],
                ]);
            }
            $this->saveMutation($current, $data, $hash, $actor, $request, 'schedule', $before);

            return $current;
        }, 3);
    }

    public function start(RentalOrder $order, RentalTransportTask $task, array $data, User $actor, ?Request $request = null): RentalTransportTask
    {
        return $this->transition($order, $task, 'start', $data, $actor, $request);
    }

    public function complete(RentalOrder $order, RentalTransportTask $task, array $data, User $actor, ?Request $request = null): RentalTransportTask
    {
        return $this->transition($order, $task, 'complete', $data, $actor, $request);
    }

    public function cancel(RentalOrder $order, RentalTransportTask $task, array $data, User $actor, ?Request $request = null): RentalTransportTask
    {
        return $this->transition($order, $task, 'cancel', $data, $actor, $request);
    }

    private function transition(RentalOrder $order, RentalTransportTask $task, string $action, array $data, User $actor, ?Request $request): RentalTransportTask
    {
        $this->authorize($actor);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'entry_key' => ['required', 'uuid']];
        if ($action === 'complete') {
            $rules += [
                'recipient_name' => ['required', 'string', 'max:150'],
                'notes' => ['required', 'string', 'max:3000'],
                'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            ];
        } elseif ($action === 'cancel') {
            $rules['notes'] = ['required', 'string', 'max:3000'];
        }
        $data = Validator::make($data, $rules)->validate();
        foreach (['recipient_name', 'notes'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '') {
                    $this->invalid($field, 'Kolom ini wajib diisi.');
                }
            }
        }
        $hash = $this->fingerprint($action, $data);
        $storedPhoto = null;
        try {
            // No automatic transaction retries: a rollback must never orphan a proof image.
            return DB::transaction(function () use ($order, $task, $action, $data, $actor, $request, $hash, &$storedPhoto) {
                $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $task = $this->lockedTask($order, $task);
                if ($this->replayed($task, $data, $hash)) {
                    return $task;
                }
                $this->checkVersion($task, $data);
                $before = $task->only(['status', 'version']);
                if (! $task->isActive() || ($action === 'start' && $task->status !== 'scheduled') || ($action === 'complete' && $task->status !== 'in_progress')) {
                    $this->invalid('status', 'Status tugas telah berubah atau urutan aksi tidak sesuai. Muat ulang halaman.');
                }
                if ($action === 'cancel') {
                    $task->fill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_notes' => $data['notes']]);
                } else {
                    // Completion remains possible after core inspection finishes the order.
                    $this->assertOrder($order, $task->type, $action === 'complete' || $task->type === 'collection');
                    if ($task->hasStaleSchedule($order)) {
                        $this->invalid('schedule_version', 'Kontrak telah diperpanjang. Jadwalkan ulang penjemputan sebelum memulai atau menyelesaikan tugas.');
                    }
                    $this->assertWindow($order, $task->type, $task->scheduled_start, $task->scheduled_end);
                    $this->lockStaff((int) $task->assigned_user_id);
                    $this->assertAvailable((int) $task->assigned_user_id, $task->scheduled_start, $task->scheduled_end, $task->id);
                    if ($action === 'start') {
                        $task->fill(['status' => 'in_progress', 'started_at' => now()]);
                    } else {
                        if ($task->type === 'delivery' && ! $order->dispatched_at) {
                            $this->invalid('status', 'Catat serah-terima / dispatch pesanan sebelum menyelesaikan pengantaran.');
                        }
                        if ($task->type === 'collection' && (! $order->items()->exists() || $order->items()->whereNull('returned_at')->exists())) {
                            $this->invalid('status', 'Catat pengembalian semua unit sebelum menyelesaikan penjemputan.');
                        }
                        if (($data['photo'] ?? null) instanceof UploadedFile) {
                            $storedPhoto = $data['photo']->store('rental-transport/'.$order->id.'/'.$task->id, 'local');
                            if (! $storedPhoto) {
                                $this->invalid('photo', 'Foto bukti gagal disimpan. Coba kembali.');
                            }
                        }
                        $task->fill([
                            'status' => 'completed', 'completed_at' => now(), 'recipient_name' => $data['recipient_name'],
                            'completion_notes' => $data['notes'], 'proof_photo_path' => $storedPhoto,
                        ]);
                    }
                }
                $this->saveMutation($task, $data, $hash, $actor, $request, $action, $before);

                return $task;
            });
        } catch (Throwable $exception) {
            if ($storedPhoto) {
                Storage::disk('local')->delete($storedPhoto);
            }
            throw $exception;
        }
    }

    public function cancelForOrder(RentalOrder $order, ?User $actor = null, ?Request $request = null): void
    {
        DB::transaction(function () use ($order, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'cancelled') {
                $this->invalid('status', 'Batalkan pesanan terlebih dahulu sebelum membatalkan seluruh tugas angkutan.');
            }
            $tasks = RentalTransportTask::where('rental_order_id', $order->id)->whereIn('status', RentalTransportTask::ACTIVE_STATUSES)->orderBy('id')->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $before = $task->only(['status', 'version']);
                $task->fill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_notes' => 'Pesanan dibatalkan.', 'updated_by' => $actor?->id, 'version' => $task->version + 1])->save();
                $this->audit->log($actor, 'rental.transport.cancel', $task, $request ?? Request::create('/'), $before, $task->only(['status', 'version']), 'Tugas angkutan dibatalkan mengikuti pesanan');
            }
        }, 3);
    }

    public function alerts(?RentalOrder $onlyOrder = null): Collection
    {
        $orders = RentalOrder::where(function ($query) {
            $query->whereIn('status', ['reserved', 'rented', 'inspection'])
                ->orWhere(function ($completed) {
                    $completed->where('status', 'completed')->whereIn('id', RentalTransportTask::select('rental_order_id')->whereIn('status', RentalTransportTask::ACTIVE_STATUSES));
                });
        })
            ->when($onlyOrder, fn ($q) => $q->whereKey($onlyOrder->id))->get();
        $tasks = RentalTransportTask::with('assignedUser')->whereIn('rental_order_id', $orders->modelKeys())->get()->groupBy('rental_order_id');
        $alerts = collect();
        foreach ($orders as $order) {
            foreach (RentalTransportTask::TYPES as $type => $label) {
                if (! $this->supports($order, $type)) {
                    continue;
                }
                $task = $tasks->get($order->id, collect())->firstWhere('type', $type);
                $base = ['order' => $order, 'unit' => null, 'blocking_order' => null];
                if (! $task || $task->status === 'cancelled') {
                    if ($order->status !== 'completed') {
                        $alerts->push($base + ['type' => 'Jadwal '.$label.' belum tersedia', 'severity' => 'warning', 'message' => $order->code.' memerlukan jadwal '.strtolower($label).'.', 'action' => 'Buka detail transaksi lalu buat atau jadwalkan ulang tugas angkutan.']);
                    }
                } elseif ($task->isActive()) {
                    if ($task->hasStaleSchedule($order)) {
                        $alerts->push($base + ['type' => 'Jadwal penjemputan kedaluwarsa', 'severity' => 'critical', 'message' => $order->code.' memiliki kontrak baru; jadwal penjemputan masih mengikuti kontrak lama.', 'action' => 'Jadwalkan ulang penjemputan sebelum memulai atau menyelesaikan tugas.']);
                    }
                    if ($task->isOverdue()) {
                        $alerts->push($base + ['type' => 'Angkutan terlambat', 'severity' => 'critical', 'message' => $label.' '.$order->code.' melewati jendela jadwal '.$task->scheduled_end->setTimezone(RentalTransportTask::TIMEZONE)->format('d M Y H:i').' WIB.', 'action' => 'Periksa petugas, catat serah-terima/pengembalian lalu selesaikan atau jadwalkan ulang tugas.']);
                    }
                }
            }
        }

        return $alerts->sortBy(fn ($alert) => $alert['severity'] === 'critical' ? 0 : 1)->values();
    }

    public function supports(RentalOrder $order, string $type): bool
    {
        return isset(RentalTransportTask::TYPES[$type]) && in_array($order->fulfilment, [$type, 'both'], true);
    }

    private function authorize(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasRole(RentalTransportTask::STAFF_ROLES)) {
            throw new AuthorizationException('Hanya owner, manager, atau admin aktif yang dapat mengelola angkutan.');
        }
    }

    private function assertOrder(RentalOrder $order, string $type, bool $allowCompleted = false): void
    {
        $statuses = $allowCompleted ? ['reserved', 'rented', 'inspection', 'completed'] : ['reserved', 'rented', 'inspection'];
        if (! in_array($order->status, $statuses, true) || $order->isExpired()) {
            $this->invalid('status', 'Jadwal hanya untuk pesanan terkonfirmasi, sedang disewa, atau dalam pemeriksaan.');
        }
        if (! $this->supports($order, $type)) {
            $this->invalid('type', 'Jenis angkutan tidak sesuai layanan antar/jemput pada pesanan.');
        }
    }

    private function parseWindow(array $data): array
    {
        return array_map(fn ($value) => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, RentalTransportTask::TIMEZONE)->setTimezone(config('app.timezone')), [$data['scheduled_start'], $data['scheduled_end']]);
    }

    private function assertWindow(RentalOrder $order, string $type, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $contractStart = CarbonImmutable::parse($order->start_date->toDateString(), RentalTransportTask::TIMEZONE);
        $contractEnd = CarbonImmutable::parse($order->end_date->toDateString(), RentalTransportTask::TIMEZONE);
        if ($end->lte($start)) {
            $this->invalid('scheduled_end', 'Akhir jendela harus setelah waktu mulai.');
        }
        if ($type === 'delivery' && ($start->lt($contractStart) || $end->gt($contractEnd->addDay()))) {
            $this->invalid('scheduled_start', 'Seluruh jendela pengantaran harus berada dalam tanggal kontrak (WIB).');
        }
        if ($type === 'collection' && $start->lt($contractEnd)) {
            $this->invalid('scheduled_start', 'Penjemputan paling awal pada tanggal akhir kontrak (WIB).');
        }
    }

    private function lockedTask(RentalOrder $order, RentalTransportTask $task): RentalTransportTask
    {
        // Explicit scoping works without adding a relationship to the existing order model.
        return RentalTransportTask::where('rental_order_id', $order->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
    }

    private function lockStaff(int $id): void
    {
        $staff = User::whereKey($id)->lockForUpdate()->first();
        if (! $staff || $staff->status !== 'active' || ! $staff->hasRole(RentalTransportTask::STAFF_ROLES)) {
            $this->invalid('assigned_user_id', 'Pilih petugas owner, manager, atau admin yang aktif.');
        }
    }

    private function assertAvailable(int $staffId, CarbonImmutable $start, CarbonImmutable $end, ?int $except): void
    {
        $conflict = RentalTransportTask::where('assigned_user_id', $staffId)
            ->whereIn('status', RentalTransportTask::ACTIVE_STATUSES)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)
            ->lockForUpdate()->first();
        if ($conflict) {
            $this->invalid('assigned_user_id', 'Jadwal petugas bertumpuk dengan tugas #'.$conflict->id.'. Pilih waktu atau petugas lain.');
        }
    }

    private function checkVersion(RentalTransportTask $task, array $data): void
    {
        if ($task->version !== (int) $data['version']) {
            $this->invalid('version', 'Jadwal telah diubah petugas lain. Muat ulang halaman sebelum melanjutkan.');
        }
    }

    private function fingerprint(string $action, array $data): string
    {
        if (($data['photo'] ?? null) instanceof UploadedFile) {
            $data['photo'] = hash_file('sha256', $data['photo']->getRealPath());
        }
        unset($data['entry_key']);
        ksort($data);

        return hash('sha256', json_encode([$action, $data], JSON_THROW_ON_ERROR));
    }

    private function replayed(RentalTransportTask $task, array $data, string $hash): bool
    {
        $receipt = ($task->mutation_receipts ?? [])[$data['entry_key']] ?? null;
        if ($receipt !== null && ! hash_equals($receipt, $hash)) {
            $this->invalid('entry_key', 'Form ini sudah dipakai dengan data berbeda. Muat ulang halaman.');
        }

        return $receipt !== null;
    }

    private function saveMutation(RentalTransportTask $task, array $data, string $hash, User $actor, ?Request $request, string $action, array $before): void
    {
        $receipts = $task->mutation_receipts ?? [];
        $receipts[$data['entry_key']] = $hash;
        $task->fill(['mutation_receipts' => $receipts, 'version' => $task->version + 1, 'updated_by' => $actor->id])->save();
        $this->audit->log($actor, 'rental.transport.'.$action, $task, $request ?? Request::create('/'), $before, $task->only(['status', 'version', 'assigned_user_id', 'schedule_version']), 'Jadwal angkutan rental diperbarui');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
