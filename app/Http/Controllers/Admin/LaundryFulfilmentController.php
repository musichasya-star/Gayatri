<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaundryDocument;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\LaundryQualityCheck;
use App\Services\CRM\LaundryFinance;
use App\Services\CRM\LaundryOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LaundryFulfilmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'workflow' => ['nullable', Rule::in(array_keys(LaundryOrder::WORKFLOW))], 'deadline' => ['nullable', Rule::in(['overdue', 'soon'])], 'include_demo' => ['nullable', 'boolean']]);
        $base = LaundryOrder::query()->where('status', '!=', 'cancelled')
            ->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where('is_demo', false))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('code', 'like', '%'.$search.'%')->orWhere('customer_snapshot->name', 'like', '%'.$search.'%')));
        $open = (clone $base)->whereNotIn('workflow_status', ['ready', 'handed_over', 'cancelled']);
        $counts = ['overdue' => (clone $open)->where('estimated_ready_at', '<', now())->count(), 'soon' => (clone $open)->whereBetween('estimated_ready_at', [now(), now()->addHours(24)])->count(), 'ready' => (clone $base)->where('workflow_status', 'ready')->count(), 'open' => (clone $base)->where('workflow_status', '!=', 'handed_over')->count()];
        $orders = $base->when($filters['workflow'] ?? null, fn ($q, $stage) => $q->where('workflow_status', $stage), fn ($q) => $q->where('workflow_status', '!=', 'handed_over'))
            ->when($filters['deadline'] ?? null, function ($q, $deadline) {
                $q->whereNotIn('workflow_status', ['ready', 'handed_over', 'cancelled']);
                $deadline === 'overdue' ? $q->where('estimated_ready_at', '<', now()) : $q->whereBetween('estimated_ready_at', [now(), now()->addHours(24)]);
            })->with('payments')->orderBy('estimated_ready_at')->orderBy('id')->paginate(20)->withQueryString();

        return response()->view('admin.laundry.operations', compact('filters', 'counts', 'orders'))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function start(Request $request, LaundryOrder $order, LaundryOperations $operations)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'steps' => ['required', 'array', 'min:2', 'max:4'], 'steps.*' => ['required', 'distinct', Rule::in(array_keys(LaundryOrder::STEPS))], 'notes' => ['nullable', 'string', 'max:1500']]);
        $operations->start($order, $data, $request->user(), $request);

        return back()->with('status', 'Pesanan masuk antrean. Ikuti tahapan pengerjaan yang tersimpan.');
    }

    public function advance(Request $request, LaundryOrder $order, LaundryOperations $operations)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'next_stage' => ['required', Rule::in(array_merge(array_keys(LaundryOrder::STEPS), ['quality_check']))], 'notes' => ['nullable', 'string', 'max:1500']]);
        $operations->advance($order, $data, $request->user(), $request);

        return back()->with('status', 'Tahap pengerjaan tersimpan beserta petugas dan waktunya.');
    }

    public function quality(Request $request, LaundryOrder $order, LaundryOperations $operations)
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'result' => ['required', Rule::in(['pass', 'rework'])], 'restart_stage' => ['nullable', 'required_if:result,rework', Rule::in(array_keys(LaundryOrder::STEPS))], 'notes' => ['nullable', 'required_if:result,rework', 'string', 'max:1500'], 'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.checked_pieces' => ['required', 'integer', 'min:0', 'max:10000'], 'items.*.notes' => ['nullable', 'string', 'max:1500']];
        foreach (array_keys(LaundryQualityCheck::CHECKS) as $check) {
            $rules['items.*.'.$check] = ['nullable', 'boolean'];
        }
        $data = $request->validate($rules);
        $operations->quality($order, $data, $request->user(), $request);

        return back()->with('status', $data['result'] === 'pass' ? 'QC lulus. Barang siap diambil; pelunasan tetap diperiksa saat serah terima.' : 'Hasil pemeriksaan disimpan. Pesanan kembali ke tahap perbaikan yang dipilih.');
    }

    public function handover(Request $request, LaundryOrder $order, LaundryOperations $operations)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'receiver_name' => ['required', 'string', 'max:150'], 'receiver_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/D'], 'notes' => ['nullable', 'string', 'max:1500'], 'confirmed' => ['required', 'accepted'], 'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.returned_pieces' => ['required', 'integer', 'min:1', 'max:10000'], 'items.*.label_confirmed' => ['required', 'accepted']]);
        $operations->handover($order, $data, $request->user(), $request);

        return back()->with('status', 'Serah terima tersimpan beserta nama penerima, jumlah barang, petugas, dan waktu.');
    }

    public function payment(Request $request, LaundryOrder $order, LaundryFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'entry_key' => ['required', 'uuid'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'method' => ['required', Rule::in(array_keys(LaundryPayment::METHODS))], 'paid_at' => ['required', 'date', 'before_or_equal:now'], 'payer_name' => ['required', 'string', 'max:150'], 'reference' => ['nullable', 'required_if:method,transfer', 'string', 'min:3', 'max:150'], 'notes' => ['nullable', 'string', 'max:1500'], 'confirmed' => ['required', 'accepted'], 'proof' => ['nullable', 'required_if:method,transfer', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120']]);
        $payment = $finance->payment($order, $data, $request->file('proof'), $request->user(), $request);

        return redirect()->route('admin.laundry.orders.show', $order)->with('status', $payment->voided_at ? 'Form ini merujuk pembayaran yang sudah dikoreksi; tidak ada pencatatan baru.' : 'Pembayaran manual tersimpan. Kuitansi tersedia di daftar dokumen.');
    }

    public function voidPayment(Request $request, LaundryOrder $order, LaundryPayment $payment, LaundryFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1500'], 'confirmed' => ['required', 'accepted']]);
        $finance->voidPayment($order, $payment, $data, $request->user(), $request);

        return back()->with('status', 'Pencatatan pembayaran dan kuitansi terkait dibatalkan. Riwayat dipertahankan; tindakan ini tidak mengirim atau mengembalikan uang.');
    }

    public function invoice(Request $request, LaundryOrder $order, LaundryFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $document = $finance->invoice($order, (int) $data['revision'], $request->user(), $request);

        return redirect()->route('admin.laundry.documents.show', [$order, $document]);
    }

    public function document(LaundryOrder $order, LaundryDocument $document)
    {
        abort_unless($document->laundry_order_id === $order->id, 404);

        return response()->view('admin.laundry.document', ['document' => $document, 'snapshot' => $document->snapshot, 'order' => $order])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function voidInvoice(Request $request, LaundryOrder $order, LaundryDocument $document, LaundryFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1500'], 'confirmed' => ['required', 'accepted']]);
        $finance->voidInvoice($order, $document, $data, $request->user(), $request);

        return back()->with('status', 'Invoice dibatalkan dan tetap tersimpan sebagai riwayat.');
    }

    public function proof(LaundryOrder $order, LaundryPayment $payment)
    {
        abort_unless($payment->laundry_order_id === $order->id && $payment->proof_path && str_starts_with($payment->proof_path, 'laundry/payment-proofs/'), 404);
        abort_unless(Storage::disk('local')->exists($payment->proof_path), 404);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$payment->proof_mime] ?? 'bin';

        return Storage::disk('local')->download($payment->proof_path, 'bukti-laundry-'.$payment->id.'.'.$extension, ['Cache-Control' => 'private, no-store', 'Content-Type' => $payment->proof_mime, 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
