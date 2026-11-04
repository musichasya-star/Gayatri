<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalExtensionRequest;
use App\Models\RentalOrder;
use App\Models\RentalPaymentProof;
use App\Services\CRM\RentalMessagingService;
use App\Services\CRM\RentalPaymentProofService;
use App\Services\CRM\RentalPortalService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalPortalAdminController extends Controller
{
    public function verifyRecipient(Request $request, RentalOrder $order, RentalMessagingService $messaging)
    {
        $data = $request->validate(['notification_phone' => ['required', 'string', 'max:30'], 'recipient_confirmed' => ['required', 'accepted']]);
        try {
            $messaging->verify($order, $data['notification_phone'], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['messaging' => $e->getMessage()]);
        }

        return back()->with('status', 'Tujuan WhatsApp pesanan tersimpan. Perubahan nomor yang sudah diverifikasi mengganti tautan portal lama.');
    }

    public function sendPortal(Request $request, RentalOrder $order, RentalMessagingService $messaging)
    {
        try {
            $row = $messaging->queuePortal($order);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['messaging' => $e->getMessage()]);
        }

        $message = match ($row?->status) {
            'sent' => 'Tautan versi ini sudah dikirim ke tujuan pesanan hari ini. Pesan tidak dikirim dua kali.',
            'skipped' => 'Tautan dilewati. Periksa status pelanggan dan tujuan WhatsApp pada riwayat pengiriman.',
            'failed', 'unknown' => 'Pengiriman sebelumnya perlu ditinjau melalui riwayat WhatsApp sebelum dicoba ulang.',
            default => 'Tautan masuk antrean. Maksimal satu pesan tautan per versi portal, tujuan, dan hari.',
        };

        return back()->with('status', $message);
    }

    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);
        $status = $request->input('status', 'pending');

        return view('admin.rentals.payment-reviews', ['statusFilter' => $status,
            'proofs' => RentalPaymentProof::with('order.customer')->where('status', $status)->latest()->paginate(15, ['*'], 'proofs_page')->withQueryString(),
            'requests' => RentalExtensionRequest::with('order.customer')->where('status', $status)->latest()->paginate(15, ['*'], 'requests_page')->withQueryString()]);
    }

    public function issue(Request $request, RentalOrder $order, RentalPortalService $portal)
    {
        $link = $portal->issue($order, $request, $request->user());

        return back()->with('portal_link', $link)->with('portal_order_id', $order->id)->with('status', 'Tautan pribadi baru dibuat. Tautan dan sesi portal sebelumnya sudah tidak berlaku.');
    }

    public function proofFile(RentalOrder $order, RentalPaymentProof $proof, RentalPaymentProofService $service)
    {
        abort_unless($proof->rental_order_id === $order->id, 404);

        return $service->file($proof);
    }

    public function proofReview(Request $request, RentalOrder $order, RentalPaymentProof $proof, RentalPaymentProofService $service)
    {
        abort_unless($proof->rental_order_id === $order->id, 404);
        $data = $request->validate(['action' => ['required', 'in:approve,reject'], 'review_notes' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $service->review($proof, $data['action'], $data['review_notes'], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', 'Bukti pembayaran selesai ditinjau.');
    }

    public function extensionReview(Request $request, RentalOrder $order, RentalExtensionRequest $entry, RentalPortalService $portal)
    {
        abort_unless($entry->rental_order_id === $order->id, 404);
        $data = $request->validate(['action' => ['required', 'in:approve,reject'], 'review_notes' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $portal->reviewExtension($entry, $data['action'], $data['review_notes'], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan perpanjangan selesai ditinjau.');
    }
}
