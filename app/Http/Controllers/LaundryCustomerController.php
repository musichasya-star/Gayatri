<?php

namespace App\Http\Controllers;

use App\Models\LaundryTariff;
use App\Services\CRM\LaundryCustomerPayment;
use App\Services\CRM\LaundryPortal;
use App\Services\CRM\LaundryPublicBooking;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\LaundryTransportPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaundryCustomerController extends Controller
{
    public function index(Request $request, LaundryPublicSettings $public, LaundrySettings $outlet, LaundryTransportPricing $transport)
    {
        if (! $request->session()->has('laundry.public_entry_key') || $request->session()->get('laundry.public_entry_used')) {
            $request->session()->put('laundry.public_entry_key', (string) Str::uuid());
            $request->session()->forget('laundry.public_entry_used');
        }

        return response()->view('laundry.index', ['settings' => $public->get(), 'outlet' => $outlet->get(), 'transport' => $transport->settings(), 'tariffs' => LaundryTariff::where('is_active', true)->orderBy('name')->get(), 'entryKey' => $request->session()->get('laundry.public_entry_key'), 'draft' => $request->session()->get('laundry.public_draft', [])])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function preview(Request $request, LaundryPublicBooking $service)
    {
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:150'], 'whatsapp_number' => ['required', 'string', 'max:30'], 'fulfilment' => ['required', Rule::in(['dropoff', 'pickup'])], 'delivery_required' => ['nullable', 'boolean'], 'preferred_at' => ['nullable', 'required_if:fulfilment,pickup', 'date_format:Y-m-d\TH:i'], 'address' => ['nullable', 'string', 'max:1500'], 'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'], 'notes' => ['nullable', 'string', 'max:3000'], 'items' => ['required', 'array', 'min:1', 'max:10'], 'items.*.laundry_tariff_id' => ['required', 'integer', 'distinct', 'exists:laundry_tariffs,id'], 'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'decimal:0,3']]);
        if ($request->session()->get('laundry.public_entry_key') !== $data['entry_key']) {
            throw ValidationException::withMessages(['laundry' => 'Buka formulir pemesanan baru pada browser ini.']);
        }
        $request->session()->put('laundry.public_draft', $data);
        try {
            $preview = $service->preview($data);
        } catch (ValidationException $exception) {
            return redirect()->route('laundry.index')->withErrors($exception->errors());
        }

        return view('laundry.preview', compact('preview'));
    }

    public function store(Request $request, LaundryPublicBooking $service, LaundryPortal $portal)
    {
        try {
            $data = $request->validate(['token' => ['required', 'string', 'max:200000'], 'confirmed' => ['required', 'accepted']]);
            $entry = $service->create($data['token'], $request);
        } catch (ValidationException $exception) {
            return redirect()->route('laundry.index')->withErrors($exception->errors());
        }
        $portal->grant($request, $entry);
        $request->session()->put('laundry.public_entry_used', true);
        $request->session()->forget('laundry.public_draft');

        return redirect()->route('laundry.portal.show', $entry->public_id)->with('status', 'Permintaan tersimpan. Simpan akses portal pribadi dan tunggu konfirmasi admin.');
    }

    public function accessPage()
    {
        return view('laundry.access');
    }

    public function access(Request $request, LaundryPortal $portal)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D']]);
        $entry = $portal->access($data['token'], $request);

        return redirect()->route('laundry.portal.show', $entry->public_id);
    }

    public function show(Request $request, string $publicId, LaundryPortal $portal, LaundryPublicSettings $settings)
    {
        $entry = $portal->authorize($publicId, $request);
        $entry->load(['pickupTask.courier', 'order.items', 'order.documents', 'order.paymentProofs.payment', 'order.transportTasks.courier']);
        $order = $entry->order;

        return view('laundry.portal', ['entry' => $entry, 'order' => $order, 'settings' => $settings->get(), 'privateLink' => $portal->link($entry), 'priceReady' => $order ? LaundryPortal::priceReady($order) : false]);
    }

    public function approve(Request $request, string $publicId, LaundryPortal $portal)
    {
        $entry = $portal->authorize($publicId, $request);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'fingerprint' => ['required', 'string', 'size:64'], 'approved_name' => ['required', 'string', 'max:150'], 'confirmed' => ['required', 'accepted']]);
        $portal->approve($entry, $data, $request);

        return back()->with('status', 'Persetujuan hasil timbang dan total tagihan tersimpan.');
    }

    public function proof(Request $request, string $publicId, LaundryPortal $portal, LaundryCustomerPayment $payments)
    {
        $entry = $portal->authorize($publicId, $request);
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'], 'fingerprint' => ['required', 'string', 'size:64'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'paid_at' => ['required', 'date', 'before_or_equal:now'], 'payer_name' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'min:3', 'max:150'], 'notes' => ['nullable', 'string', 'max:1500'], 'confirmed' => ['required', 'accepted'], 'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120']]);
        $proof = $payments->upload($entry, $data, $request->file('file'), $request);

        return back()->with('status', $proof->status === 'pending' ? 'Bukti diterima dan menunggu pemeriksaan admin. Sisa tagihan belum berubah.' : 'Form ini sudah pernah diproses. Periksa status bukti pada riwayat.');
    }

    public function proofFile(Request $request, string $publicId, int $proofId, LaundryPortal $portal)
    {
        $entry = $portal->authorize($publicId, $request);
        abort_unless($entry->order, 404);
        $proof = $entry->order->paymentProofs()->where('laundry_public_request_id', $entry->id)->findOrFail($proofId);
        abort_unless(str_starts_with($proof->file_path, 'laundry/payment-proofs/') && Storage::disk('local')->exists($proof->file_path), 404);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'][$proof->mime_type] ?? 'bin';

        return Storage::disk('local')->download($proof->file_path, 'bukti-laundry-'.$proof->id.'.'.$extension);
    }

    public function document(Request $request, string $publicId, int $documentId, LaundryPortal $portal)
    {
        $entry = $portal->authorize($publicId, $request);
        abort_unless($entry->order, 404);
        $document = $entry->order->documents()->findOrFail($documentId);

        return view('admin.laundry.document', ['document' => $document, 'snapshot' => $document->snapshot, 'order' => $entry->order, 'backUrl' => route('laundry.portal.show', $entry->public_id)]);
    }
}
