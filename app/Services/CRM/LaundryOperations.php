<?php

namespace App\Services\CRM;

use App\Models\LaundryOrder;
use App\Models\LaundryQualityCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LaundryOperations
{
    public function __construct(private readonly LaundryActivity $activity) {}

    public static function nextStage(LaundryOrder $order): ?string
    {
        $steps = $order->process_steps ?? [];
        if ($order->workflow_status === 'queued') {
            return $steps[0] ?? null;
        }
        $index = array_search($order->workflow_status, $steps, true);

        return $index === false ? null : ($steps[$index + 1] ?? 'quality_check');
    }

    public function start(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            if ($order->transportTasks()->where('kind', 'pickup')->whereIn('status', ['planned', 'in_transit', 'failed'])->exists()) {
                throw ValidationException::withMessages(['laundry' => 'Selesaikan penjemputan terhubung sebelum memulai pengerjaan.']);
            }
            if ($order->status !== 'approved' || ! $order->approved_at || $order->actual_total === null || $order->workflow_status !== 'pending') {
                throw ValidationException::withMessages(['laundry' => 'Masuk antrean memerlukan hasil timbang dan persetujuan nilai jasa. Pesanan yang sudah berjalan tidak dapat dimulai ulang.']);
            }
            LaundryPortal::requireApprovedPrice($order);
            $steps = array_values(array_intersect(array_keys(LaundryOrder::STEPS), $data['steps']));
            if (! in_array('packing', $steps, true) || count($steps) < 2 || (in_array('washing', $steps, true) && ! in_array('drying', $steps, true))) {
                throw ValidationException::withMessages(['steps' => 'Pilih minimal satu tahap pengerjaan dan kemas. Proses cuci harus diikuti pengeringan.']);
            }
            $order->update(['workflow_status' => 'queued', 'process_steps' => $steps, 'processing_started_at' => now()]);
            $this->activity->record($order, 'work_started', $actor, ['steps' => $steps, 'notes' => $data['notes'] ?? null], $request);

            return $order;
        });
    }

    public function advance(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            $next = self::nextStage($order);
            if ($order->status !== 'approved' || $next === null || $data['next_stage'] !== $next) {
                throw ValidationException::withMessages(['laundry' => 'Tahap pengerjaan harus mengikuti urutan rencana yang tersimpan.']);
            }
            $before = $order->workflow_status;
            $order->update(['workflow_status' => $next]);
            $this->activity->record($order, 'stage_changed', $actor, ['from' => $before, 'to' => $next, 'notes' => $data['notes'] ?? null], $request);

            return $order;
        });
    }

    private function ownedItems(LaundryOrder $order, array $input)
    {
        $items = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $ids = collect($input)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($ids !== $items->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages(['items' => 'Periksa seluruh kantong pesanan ini, tanpa duplikasi atau kantong dari pesanan lain.']);
        }

        return $items;
    }

    public function quality(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            if ($order->workflow_status !== 'quality_check' || $order->status !== 'approved') {
                throw ValidationException::withMessages(['laundry' => 'Pemeriksaan kualitas dilakukan setelah seluruh tahap pengerjaan selesai.']);
            }
            $items = $this->ownedItems($order, $data['items']);
            $snapshots = [];
            $allPassed = true;
            foreach ($data['items'] as $row) {
                $item = $items[(int) $row['id']];
                $checks = [];
                foreach (LaundryQualityCheck::CHECKS as $key => $label) {
                    $checks[$key] = (bool) ($row[$key] ?? false);
                }
                $count = (int) $row['checked_pieces'];
                $passed = $count === $item->actual_pieces && ! in_array(false, $checks, true);
                $allPassed = $allPassed && $passed;
                $snapshots[] = ['id' => $item->id, 'bag_code' => $item->bag_code, 'bag_label' => $item->bag_label, 'expected_pieces' => $item->actual_pieces, 'checked_pieces' => $count, 'checks' => $checks, 'notes' => $row['notes'] ?? null];
            }
            $passed = $data['result'] === 'pass';
            if ($passed && ! $allPassed) {
                throw ValidationException::withMessages(['items' => 'Untuk lulus QC, jumlah potong harus sesuai hasil timbang dan seluruh checklist setiap kantong harus terpenuhi.']);
            }
            if (! $passed && (mb_strlen(trim($data['notes'] ?? '')) < 5 || ! in_array($data['restart_stage'] ?? '', $order->process_steps ?? [], true))) {
                throw ValidationException::withMessages(['notes' => 'Catat masalah minimal 5 karakter dan pilih tahap pengerjaan ulang sesuai rencana.']);
            }
            $check = $order->qualityChecks()->create(['actor_id' => $actor->id, 'passed' => $passed, 'items' => $snapshots, 'notes' => $data['notes'] ?? null, 'restart_stage' => $passed ? null : $data['restart_stage'], 'revision' => $order->revision + 1, 'created_at' => now()]);
            $order->update(['workflow_status' => $passed ? 'ready' : $data['restart_stage'], 'ready_at' => $passed ? now() : null]);
            $this->activity->record($order, $passed ? 'quality_passed' : 'quality_rework', $actor, ['quality_check_id' => $check->id, 'items' => $snapshots, 'notes' => $check->notes, 'restart_stage' => $check->restart_stage], $request);

            return $order;
        });
    }

    public function handover(LaundryOrder $order, array $data, User $actor, Request $request, ?int $transportTaskId = null): LaundryOrder
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $data, $actor, $request, $transportTaskId) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            if ($order->transportTasks()->where('kind', 'delivery')->whereIn('status', ['planned', 'in_transit', 'failed'])->when($transportTaskId, fn ($q) => $q->whereKeyNot($transportTaskId))->exists()) {
                throw ValidationException::withMessages(['laundry' => 'Ada pengantaran aktif. Catat serah terima melalui penyelesaian kunjungan pengantaran.']);
            }
            if ($order->status !== 'approved' || $order->workflow_status !== 'ready' || ! $order->qualityChecks()->latest('id')->first()?->passed) {
                throw ValidationException::withMessages(['laundry' => 'Serah terima memerlukan pesanan siap diambil dan pemeriksaan kualitas yang lulus.']);
            }
            if ($order->actual_total === null || (int) $order->payments()->whereNull('voided_at')->sum('amount') !== $order->totalDue()) {
                throw ValidationException::withMessages(['laundry' => 'Lunasi seluruh nilai jasa beserta ongkir sebelum menyerahkan barang.']);
            }
            $items = $this->ownedItems($order, $data['items']);
            $snapshot = [];
            foreach ($data['items'] as $row) {
                $item = $items[(int) $row['id']];
                if ((int) $row['returned_pieces'] !== $item->actual_pieces || ! ($row['label_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['items' => 'Cocokkan label dan jumlah potong setiap kantong dengan hasil timbang sebelum diserahkan.']);
                }
                $snapshot[] = ['id' => $item->id, 'bag_code' => $item->bag_code, 'bag_label' => $item->bag_label, 'returned_pieces' => (int) $row['returned_pieces'], 'label_confirmed' => true];
            }
            $order->update(['workflow_status' => 'handed_over', 'handed_over_at' => now(), 'receiver_name' => $data['receiver_name'], 'receiver_phone' => $data['receiver_phone'] ?? null, 'handover_notes' => $data['notes'] ?? null]);
            $this->activity->record($order, 'handed_over', $actor, ['receiver_name' => $order->receiver_name, 'receiver_phone' => $order->receiver_phone, 'items' => $snapshot, 'paid' => $order->totalDue(), 'notes' => $order->handover_notes], $request);

            return $order;
        });
    }
}
