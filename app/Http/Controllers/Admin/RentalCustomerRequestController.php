<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalCustomerRequest;
use App\Models\RentalOrder;
use App\Services\CRM\RentalCustomerRequestService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalCustomerRequestController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,settled']]);
        $status = $request->input('status', 'pending');

        return view('admin.rentals.customer-requests', ['statusFilter' => $status, 'entries' => RentalCustomerRequest::with('order.customer')->where('status', $status)->latest()->paginate(15)->withQueryString()]);
    }

    public function review(Request $request, RentalOrder $order, RentalCustomerRequest $entry, RentalCustomerRequestService $service)
    {
        abort_unless($entry->rental_order_id === $order->id, 404);
        $data = $request->validate(['action' => ['required', 'in:approve,reject'], 'review_notes' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $service->review($entry, $data['action'], $data['review_notes'], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['customer_request' => $e->getMessage()]);
        }

        return back()->with('status', 'Keputusan tersimpan. Jika ada refund disetujui, lakukan pengembalian dana lalu catat buktinya.');
    }

    public function settle(Request $request, RentalOrder $order, RentalCustomerRequest $entry, RentalCustomerRequestService $service)
    {
        abort_unless($entry->rental_order_id === $order->id, 404);
        $data = $request->validate(['method' => ['required', 'in:cash,transfer,other'], 'party_name' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'max:150'],
            'refund_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.($entry->reviewed_at ?? $entry->created_at)->toDateString()],
            'refund_completed' => ['required', 'accepted'], 'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120']]);
        unset($data['proof'], $data['refund_completed']);
        try {
            $service->settle($entry, $data, $request->file('proof'), $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['customer_request' => $e->getMessage()]);
        }

        return back()->with('status', 'Refund tercatat beserta bukti dan kuitansi. Tidak ada transfer bank yang dijalankan aplikasi.');
    }

    public function file(RentalOrder $order, RentalCustomerRequest $entry, RentalCustomerRequestService $service)
    {
        abort_unless($entry->rental_order_id === $order->id, 404);

        return $service->file($entry);
    }
}
