<?php

namespace App\Services\CRM;

use App\Models\RentalProduct;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RentalCart
{
    public function items(Request $request): array
    {
        return $request->session()->get('rental_cart', []);
    }

    public function add(Request $request, int $productId, int $quantity): void
    {
        RentalProduct::where('is_active', true)->findOrFail($productId);
        $items = $this->items($request);
        $items[$productId] = ($items[$productId] ?? 0) + $quantity;
        $this->save($request, $items);
    }

    public function update(Request $request, int $productId, int $quantity): void
    {
        $items = $this->items($request);
        abort_unless(array_key_exists($productId, $items), 404);
        if ($quantity === 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = $quantity;
        }
        $this->save($request, $items);
    }

    private function save(Request $request, array $items): void
    {
        if (count($items) > 10 || array_sum($items) > 20 || collect($items)->contains(fn ($quantity) => $quantity < 1 || $quantity > 20)) {
            throw new InvalidArgumentException('Keranjang maksimal 10 jenis alat dan total 20 unit.');
        }
        $request->session()->put('rental_cart', $items);
    }
}
