<?php

namespace App\Http\Controllers;

use App\Models\RentalCustomerRequest;
use App\Models\RentalDocument;
use App\Models\RentalPaymentProof;
use App\Services\CRM\RentalCustomerRequestService;
use App\Services\CRM\RentalExtensionService;
use App\Services\CRM\RentalPaymentProofService;
use App\Services\CRM\RentalPaymentTerms;
use App\Services\CRM\RentalPolicy;
use App\Services\CRM\RentalPortalService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalPortalController extends Controller
{
    public function access(Request $request, string $token, RentalPortalService $portal)
    {
        $order = $portal->grant($request, $token);

        return redirect()->route('rental.portal.show', $order->code);
    }

    public function show(Request $request, string $code, RentalPortalService $portal, RentalPaymentTerms $terms)
    {
        $order = $portal->authorize($request, $code)->load('items', 'payments', 'paymentProofs', 'extensionRequests', 'transportTasks', 'documents', 'customerRequests');
        $requests = app(RentalCustomerRequestService::class);
        $requestPreview = null;
        if (! $requests->hasOpenRequest($order)) {
            try {
                $kind = in_array($order->status, ['pending', 'reserved']) ? 'cancellation' : 'refund';
                $requestPreview = $requests->preview($order, $kind);
            } catch (InvalidArgumentException) {
                // No eligible customer action for the current status/balance.
            }
        }

        return view('rental.portal.show', ['order' => $order, 'paymentSummary' => $terms->summary($order), 'requestPreview' => $requestPreview]);
    }

    public function acceptTerms(Request $request, string $code, RentalPortalService $portal, RentalPolicy $policy)
    {
        $order = $portal->authorize($request, $code);
        $data = $request->validate(['terms_hash' => ['required', 'string', 'size:64'], 'terms_accepted' => ['required', 'accepted']]);
        try {
            $policy->accept($order, $data['terms_hash'], $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['terms' => $e->getMessage()]);
        }

        return back()->with('status', 'Persetujuan ketentuan pesanan sudah tersimpan.');
    }

    public function customerRequest(Request $request, string $code, RentalPortalService $portal, RentalCustomerRequestService $service)
    {
        $order = $portal->authorize($request, $code);
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'kind' => ['required', 'in:cancellation,refund'], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'request_token' => ['required', 'string', 'max:10000'], 'estimate_accepted' => ['required', 'accepted']]);
        try {
            $service->submit($order, $data, $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['customer_request' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan diterima dan menunggu keputusan admin. Pesanan dan saldo belum berubah.');
    }

    public function refundFile(Request $request, string $code, RentalCustomerRequest $entry, RentalPortalService $portal, RentalCustomerRequestService $service)
    {
        $order = $portal->authorize($request, $code);
        abort_unless($entry->rental_order_id === $order->id, 404);

        return $service->file($entry);
    }

    public function proof(Request $request, string $code, RentalPortalService $portal, RentalPaymentProofService $proofs)
    {
        $order = $portal->authorize($request, $code);
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'kind' => ['required', 'in:dp,settlement'], 'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payer_name' => ['required', 'string', 'max:150'], 'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$order->created_at->toDateString()],
            'reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120']]);
        unset($data['proof']);
        try {
            $proofs->submit($order, $data, $request->file('proof'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payment' => $e->getMessage()])->withInput($request->except('proof'));
        }

        return back()->with('status', 'Bukti diterima dan menunggu verifikasi admin. Saldo diperbarui setelah disetujui.');
    }

    public function proofFile(Request $request, string $code, RentalPaymentProof $proof, RentalPortalService $portal, RentalPaymentProofService $proofs)
    {
        $order = $portal->authorize($request, $code);
        abort_unless($proof->rental_order_id === $order->id, 404);

        return $proofs->file($proof);
    }

    public function document(Request $request, string $code, RentalDocument $document, RentalPortalService $portal)
    {
        $order = $portal->authorize($request, $code);
        abort_unless($document->rental_order_id === $order->id, 404);

        return view('rental.portal.document', ['order' => $order, 'document' => $document, 'snapshot' => $document->snapshot]);
    }

    public function extensionPreview(Request $request, string $code, RentalPortalService $portal, RentalExtensionService $service)
    {
        $order = $portal->authorize($request, $code);
        $data = $request->validate(['new_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        try {
            return response()->json($service->preview($order, $data['new_end_date']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function extension(Request $request, string $code, RentalPortalService $portal)
    {
        $order = $portal->authorize($request, $code);
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'new_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'extension_token' => ['required', 'string', 'max:30000'], 'notes' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $portal->requestExtension($order, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan perpanjangan diterima. Jadwal dan biaya berubah setelah disetujui admin.');
    }
}
