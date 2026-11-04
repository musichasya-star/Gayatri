<?php

namespace App\Http\Controllers;

use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Services\CRM\RentalInventory;
use App\Services\CRM\RentalPortalService;
use App\Services\CRM\RentalQuote;
use App\Services\CRM\RentalService;
use App\Services\CRM\RentalSettings;
use App\Support\CatalogueSearch;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalController extends Controller
{
    public function index(Request $request, RentalSettings $settings, RentalInventory $inventory)
    {
        $request->validate(['start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d', 'after_or_equal:today'], 'end_date' => ['nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'search' => ['nullable', 'string', 'max:100']]);
        $products = CatalogueSearch::apply(RentalProduct::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%']), $request->input('search'), ['name', 'category'])->withCount('units')->orderBy('name')->paginate(12)->withQueryString();
        $available = [];
        if ($request->filled(['start_date', 'end_date'])) {
            foreach ($products as $product) {
                $available[$product->id] = $inventory->available($product, $request->start_date, $request->end_date)->count();
            }
        }

        return view('rental.index', ['products' => $products, 'available' => $available, 'settings' => $settings->get()]);
    }

    public function show(Request $request, RentalProduct $product, RentalSettings $settings)
    {
        abort_unless($product->is_active && ! str_starts_with(mb_strtoupper(trim($product->name)), '[DEMO]'), 404);
        $product->loadCount('units');

        $view = view('rental.show', ['product' => $product, 'products' => collect([$product]), 'settings' => $settings->get()]);
        if ($request->session()->hasOldInput()) {
            return response($view, 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
        }

        return $view;
    }

    public function quote(Request $request, RentalQuote $quotes)
    {
        $data = $request->validate(RentalQuote::rules());
        try {
            return response()->json($quotes->calculate($data));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, RentalService $service)
    {
        $data = $request->validate([...RentalQuote::rules(), 'name' => ['required', 'string', 'max:150'], 'whatsapp_number' => ['required', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'notes' => ['nullable', 'string', 'max:3000'], 'quote_token' => ['required', 'string', 'max:1000000'], 'terms_accepted' => ['required', 'accepted']]);
        try {
            $order = $service->create($data, null, $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()])->withInput();
        }
        $ids = $request->session()->get('rental_order_ids', []);
        $ids[] = $order->id;
        $request->session()->put('rental_order_ids', array_values(array_unique($ids)));
        if (! $order->portal_token_hash) {
            $link = app(RentalPortalService::class)->issue($order, $request);
            $order->refresh();
            $request->session()->put('rental_portal_links.'.$order->id, ['version' => $order->portal_version, 'link' => $link]);
            $request->session()->put('rental_portal_access.'.$order->id, $order->portal_version);
        }
        $request->session()->forget('rental_cart');

        return redirect()->route('rental.receipt', $order->code);
    }

    public function receipt(Request $request, string $code, RentalSettings $settings)
    {
        $order = RentalOrder::where('code', $code)->whereIn('id', $request->session()->get('rental_order_ids', []))->with(['items', 'payments'])->firstOrFail();

        $saved = $request->session()->get('rental_portal_links.'.$order->id);
        $portalLink = $saved && (int) $saved['version'] === (int) $order->portal_version && $order->portal_expires_at?->isFuture() ? $saved['link'] : null;

        return view('rental.receipt', ['order' => $order, 'settings' => $settings->get(), 'portalLink' => $portalLink]);
    }
}
