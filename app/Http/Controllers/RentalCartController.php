<?php

namespace App\Http\Controllers;

use App\Models\RentalProduct;
use App\Services\CRM\RentalCart;
use App\Services\CRM\RentalSettings;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalCartController extends Controller
{
    public function index(Request $request, RentalCart $cart, RentalSettings $settings)
    {
        $items = $cart->items($request);
        $cartProducts = RentalProduct::whereIn('id', array_keys($items))->get()->keyBy('id');
        $unavailable = collect($items)->keys()->contains(fn ($id) => ! $cartProducts->get($id)?->is_active);

        return response()->view('rental.cart', ['cartItems' => $items, 'cartProducts' => $cartProducts, 'unavailable' => $unavailable,
            'products' => RentalProduct::where('is_active', true)->orderBy('name')->get(), 'settings' => $settings->get(),
            'initialItems' => collect($items)->map(fn ($quantity, $id) => ['product_id' => $id, 'quantity' => $quantity])->values()->all()])->header('Cache-Control', 'private, no-store');
    }

    public function add(Request $request, RentalCart $cart)
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:20']]);
        try {
            $cart->add($request, $data['product_id'], $data['quantity']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return redirect()->route('rental.cart.index')->with('status', 'Alat ditambahkan ke keranjang. Pilih tanggal lalu hitung ketersediaan.');
    }

    public function update(Request $request, int $product, RentalCart $cart)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:20']]);
        try {
            $cart->update($request, $product, $data['quantity']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return back()->with('status', 'Keranjang diperbarui.');
    }
}
