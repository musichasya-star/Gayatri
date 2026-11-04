<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalDocument;
use App\Models\RentalOrder;
use App\Models\RentalPayment;
use App\Services\CRM\RentalDocumentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Integration (inside the existing admin/rentals prefix and name group):
 * GET  documents                                  documents.index
 * GET  orders/{order}/documents/{document}         documents.show
 * POST orders/{order}/invoice                      orders.invoice
 * POST orders/{order}/payments/{payment}/receipt   orders.receipt
 * Actions: index, show, invoice, receipt. No update/delete routes.
 */
class RentalDocumentController extends Controller implements HasMiddleware
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin'];

    public static function middleware(): array
    {
        return ['auth', 'active', 'role:owner,manager,admin'];
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'order_id' => ['nullable', 'integer', 'min:1'],
            'order_code' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::in(array_keys(RentalDocument::TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
        ]);
        $documents = RentalDocument::query()
            ->when($request->filled('order_id'), fn ($q) => $q->where('rental_order_id', $request->integer('order_id')))
            ->when($request->filled('order_code'), fn ($q) => $q->where('snapshot->order->code', 'like', '%'.$request->input('order_code').'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issued_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issued_at', '<=', $request->input('to')))
            ->orderByDesc('issued_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('admin.rentals.documents.index', compact('documents', 'filters'), 200, self::PRIVATE_HEADERS);
    }

    public function show(RentalOrder $order, RentalDocument $document)
    {
        abort_unless((int) $document->rental_order_id === (int) $order->id, 404);

        // Render exclusively from issued data, never from live relations/settings.
        return response()->view('admin.rentals.documents.show', ['document' => $document, 'snapshot' => $document->snapshot], 200, self::PRIVATE_HEADERS);
    }

    public function invoice(Request $request, RentalOrder $order, RentalDocumentService $service)
    {
        try {
            $document = $service->issueInvoice($order, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rental_document' => $e->getMessage()]);
        }

        return $this->redirectToDocument($document);
    }

    public function receipt(Request $request, RentalOrder $order, RentalPayment $payment, RentalDocumentService $service)
    {
        abort_unless((int) $payment->rental_order_id === (int) $order->id, 404);
        try {
            $document = $service->issueReceipt($order, $payment, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rental_document' => $e->getMessage()]);
        }

        return $this->redirectToDocument($document);
    }

    private function redirectToDocument(RentalDocument $document)
    {
        return redirect()->route('admin.rentals.documents.show', ['order' => $document->rental_order_id, 'document' => $document->id])
            ->with('status', 'Dokumen '.$document->number.' siap. Data yang sama membuka kembali dokumen yang sudah terbit.')
            ->withHeaders(self::PRIVATE_HEADERS);
    }
}
