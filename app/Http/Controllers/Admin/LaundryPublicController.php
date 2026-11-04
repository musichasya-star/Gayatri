<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaundryPaymentProof;
use App\Models\LaundryPublicRequest;
use App\Services\CRM\LaundryCustomerPayment;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\LaundryPortal;
use App\Services\CRM\LaundryPublicBooking;
use App\Services\CRM\LaundryPublicSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LaundryPublicController extends Controller
{
    private function page(string $view, array $data = [])
    {
        return response()->view('admin.laundry.'.$view, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(LaundryPublicRequest::STATUSES))], 'include_demo' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);
        $entries = LaundryPublicRequest::with('order')->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where('is_demo', false))->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))->when($filters['search'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$value.'%')->orWhere('public_id', $value)))->latest('id')->paginate(20)->withQueryString();

        return $this->page('requests.index', compact('entries', 'filters'));
    }

    public function show(LaundryPublicRequest $entry)
    {
        return $this->page('requests.show', ['entry' => $entry->load(['order', 'customer', 'pickupTask.courier'])]);
    }

    public function verify(Request $request, LaundryPublicRequest $entry, LaundryPublicBooking $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['verify', 'reject'])], 'reference' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $service->verify($entry, $data, $request->user(), $request);

        return back()->with('status', $data['decision'] === 'verify' ? 'Kontak dikonfirmasi. Siapkan jadwal jemput atau penerimaan ketika barang benar-benar sampai.' : 'Permintaan ditolak dengan catatan yang dapat dilihat pelanggan.');
    }

    public function intake(Request $request, LaundryPublicRequest $entry)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        abort_unless($entry->status === 'confirmed' && $entry->verified_at && ! $entry->laundry_order_id, 422);
        $data = ['public_request_id' => $entry->id, 'entry_key' => (string) Str::uuid(), 'customer_id' => $entry->customer_id, 'received_at' => now()->format('Y-m-d\TH:i'), 'intake_mode' => $entry->fulfilment === 'pickup' ? 'collection' : 'walkin', 'collection_address' => $entry->address, 'is_demo' => $entry->is_demo, 'instructions' => $entry->notes, 'items' => collect($entry->items)->map(fn ($line, $index) => ['laundry_tariff_id' => $line['tariff']['id'], 'bag_label' => 'Kantong '.($index + 1), 'received_pieces' => '', 'estimated_quantity' => $line['tariff']['unit'] === 'kg' && $line['quantity_milli'] !== null ? $line['quantity_milli'] / 1000 : '', 'condition_notes' => ''])->all()];
        app(LaundryPublicBooking::class)->intakeEntry($data);
        $request->session()->put('laundry.intake_draft', $data);

        return redirect()->route('admin.laundry.orders.create')->with('status', 'Periksa jumlah potong dan kondisi aktual setiap kantong. Pilihan pelanggan merupakan estimasi.');
    }

    public function pickup(Request $request, LaundryPublicRequest $entry)
    {
        abort_unless($entry->status === 'confirmed' && $entry->verified_at && $entry->fulfilment === 'pickup' && ! $entry->laundry_order_id, 422);
        $data = ['public_request_id' => $entry->id, 'entry_key' => (string) Str::uuid(), 'kind' => 'pickup', 'customer_id' => $entry->customer_id, 'is_demo' => $entry->is_demo, 'address' => $entry->address, 'latitude' => $entry->latitude, 'longitude' => $entry->longitude, 'scheduled_start' => $entry->preferred_at?->isFuture() ? $entry->preferred_at->format('Y-m-d\TH:i') : '', 'scheduled_end' => $entry->preferred_at?->isFuture() ? $entry->preferred_at->copy()->addHour()->format('Y-m-d\TH:i') : '', 'distance_source' => 'coordinates', 'approved_name' => $entry->name, 'notes' => $entry->notes];
        app(LaundryPublicBooking::class)->pickupEntry($data);
        $request->session()->put('laundry.transport_draft', $data);

        return redirect()->route('admin.laundry.transport.create')->with('status', 'Periksa titik, petugas, jadwal, serta persetujuan ongkir. Waktu pilihan pelanggan belum menjadi jadwal pasti.');
    }

    public function collectAtOutlet(Request $request, LaundryPublicRequest $entry, LaundryPublicBooking $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reference' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $service->confirmOutletCollection($entry, $data, $request->user(), $request);

        return back()->with('status', 'Kesepakatan pengambilan di outlet dicatat. Tinjau ongkir yang masih tercatat sebelum meminta persetujuan ulang.');
    }

    public function settings(LaundryPublicSettings $settings)
    {
        return $this->page('requests.settings', ['settings' => $settings->get()]);
    }

    public function saveSettings(Request $request, LaundryPublicSettings $settings)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'intro' => ['required', 'string', 'max:1500'], 'bank_name' => ['nullable', 'string', 'max:100'], 'account_number' => ['nullable', 'string', 'max:80'], 'account_holder' => ['nullable', 'string', 'max:150'], 'payment_instructions' => ['required', 'string', 'max:3000'], 'portal_days' => ['required', 'integer', 'min:1', 'max:180'], 'portal_base_url' => ['nullable', 'url:http,https', 'max:250']]);
        if (! empty($data['portal_base_url'])) {
            abort_unless(! parse_url($data['portal_base_url'], PHP_URL_USER) && ! parse_url($data['portal_base_url'], PHP_URL_QUERY) && ! parse_url($data['portal_base_url'], PHP_URL_FRAGMENT) && in_array(parse_url($data['portal_base_url'], PHP_URL_PATH), [null, '', '/'], true), 422);
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['portal_base_url'] = $data['portal_base_url'] ?? '';
        $settings->save($data, $request->user(), $request);

        return back()->with('status', 'Pengaturan pemesanan Laundry dan instruksi pembayaran manual tersimpan.');
    }

    public function portal(Request $request, LaundryPublicRequest $entry, LaundryPortal $portal)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['open', 'share', 'regenerate', 'revoke', 'whatsapp'])]]);
        if ($data['action'] === 'regenerate') {
            $portal->issue($entry, $request, $request->user());

            return back()->with('status', 'Tautan portal diganti; tautan dan sesi lama tidak berlaku lagi.');
        }
        if ($data['action'] === 'revoke') {
            $portal->revoke($entry, $request->user(), $request);

            return back()->with('status', 'Akses portal dicabut.');
        }
        $entry->refresh();
        if ($data['action'] === 'whatsapp') {
            abort_unless($entry->order && $entry->verified_at && $portal->link($entry), 422);
            $row = app(LaundryNotificationService::class)->enqueue($entry->order, null, 'portal_shared', 'portal:'.$entry->id.':'.$entry->portal_version);
            if (! $row) {
                return back()->withErrors(['notification' => 'Aktifkan WhatsApp Laundry dan pilih notifikasi Bagikan portal pribadi terlebih dahulu.']);
            }

            return back()->with('status', match ($row->status) {
                'simulated' => 'Simulasi berbagi portal dicatat. Tidak ada pesan WhatsApp yang dikirim.',
                'queued' => 'Tautan portal masuk antrean WhatsApp Laundry. Periksa riwayat pengiriman.',
                'sent' => 'Tautan portal ini sudah pernah dikirim. Periksa riwayat WhatsApp Laundry.',
                default => 'Portal belum dikirim. Periksa status dan alasan pada riwayat WhatsApp Laundry.',
            });
        }
        if ($data['action'] === 'share') {
            abort_unless($portal->link($entry), 422);

            return view('laundry.share', ['privateLink' => $portal->link($entry), 'backUrl' => route('admin.laundry.requests.show', $entry)]);
        }
        $portal->grant($request, $entry);

        return redirect()->route('laundry.portal.show', $entry->public_id);
    }

    public function proofs(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(LaundryPaymentProof::STATUSES))], 'include_demo' => ['nullable', 'boolean']]);
        $proofs = LaundryPaymentProof::with(['order', 'publicRequest'])->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->whereHas('order', fn ($q) => $q->where('is_demo', false)))->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))->latest('id')->paginate(20)->withQueryString();

        return $this->page('requests.proofs', compact('proofs', 'filters'));
    }

    public function reviewProof(Request $request, LaundryPaymentProof $proof, LaundryCustomerPayment $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['required', 'string', 'min:5', 'max:1500'], 'confirmed' => ['required', 'accepted']]);
        $service->review($proof, $data, $request->user(), $request);

        return back()->with('status', $data['decision'] === 'approve' ? 'Bukti disetujui. Pembayaran dan kuitansi dicatat satu kali.' : 'Bukti ditolak; catatan dapat dilihat pelanggan, tagihan belum berkurang.');
    }

    public function proofFile(LaundryPaymentProof $proof)
    {
        abort_unless(str_starts_with($proof->file_path, 'laundry/payment-proofs/') && Storage::disk('local')->exists($proof->file_path), 404);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'][$proof->mime_type] ?? 'bin';

        return Storage::disk('local')->download($proof->file_path, 'bukti-pelanggan-laundry-'.$proof->id.'.'.$extension, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
