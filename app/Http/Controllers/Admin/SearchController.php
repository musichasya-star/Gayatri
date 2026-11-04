<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Services\CRM\InboxAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        if (is_string($request->input('q'))) {
            $request->merge(['q' => trim($request->input('q'))]);
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'min:2', 'max:100']]);
        $term = $data['q'] ?? '';
        $groups = [];
        if ($term !== '') {
            // Bind values and treat %, _ and ! literally on both SQLite and MySQL.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $match = static function (Builder $query, array $fields) use ($like): void {
                $query->where(function (Builder $sub) use ($fields, $like) {
                    foreach ($fields as $field) {
                        $sub->orWhereRaw($field." LIKE ? ESCAPE '!'", [$like]);
                    }
                });
            };
            $customerMatch = fn (Builder $q) => $match($q, ['name', 'phone', 'whatsapp_number']);
            $actor = $request->user();

            // Search uses the same therapist assignment/branch boundary as Inbox.
            $inbox = InboxAccess::query($actor)->with('customer')
                ->where(function (Builder $q) use ($match, $customerMatch) {
                    $match($q, ['wa_chat_id']);
                    $q->orWhereHas('customer', $customerMatch);
                })->latest('last_message_at')->limit(6)->get();
            $groups['Percakapan WhatsApp'] = $inbox->map(fn ($row) => [
                'title' => $row->customer?->name ?? 'Percakapan WhatsApp',
                'detail' => 'Buka percakapan', 'url' => route('admin.inbox', ['conversation' => $row->id]),
            ]);

            if ($actor->hasRole(['owner', 'manager', 'admin', 'sales'])) {
                $customers = Customer::query();
                $customerMatch($customers);
                $groups['Pelanggan'] = $customers->orderBy('name')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => 'Profil pelanggan', 'url' => route('admin.customers.show', $row),
                ]);
                $campaigns = Campaign::query();
                $match($campaigns, ['name']);
                $groups['Campaign'] = $campaigns->latest()->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => 'Kelola campaign', 'url' => route('admin.campaigns.edit', $row),
                ]);
            }

            if ($actor->hasRole(['owner', 'manager', 'admin'])) {
                $groups['Booking'] = Booking::with(['customer', 'service'])->where(function (Builder $q) use ($match, $customerMatch) {
                    $match($q, ['booking_code']);
                    $q->orWhereHas('customer', $customerMatch)->orWhereHas('service', fn ($services) => $match($services, ['name']));
                })->latest('booking_date')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->booking_code,
                    'detail' => $row->customer?->name.' · '.$row->booking_date?->format('d M Y').' · '.$row->service?->name,
                    'url' => route('admin.bookings.edit', $row),
                ]);
                $services = Service::query();
                $match($services, ['name', 'category', 'catalog_code', 'catalog_family', 'variant_label']);
                $groups['Layanan'] = $services->orderBy('name')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => $row->groupLabel().' · '.($row->is_active ? 'Aktif' : 'Diarsipkan'),
                    'url' => route('admin.services.edit', $row),
                ]);
                $rentalProducts = RentalProduct::query();
                $match($rentalProducts, ['name', 'sku', 'category']);
                $groups['Katalog alat rental'] = $rentalProducts->orderBy('name')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => $row->sku.' · '.$row->category, 'url' => route('admin.rentals.products.edit', $row),
                ]);
                $tariffs = LaundryTariff::query();
                $match($tariffs, ['name', 'code']);
                $groups['Tarif laundry'] = $tariffs->orderBy('name')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => $row->code, 'url' => route('admin.laundry.tariffs.edit', $row),
                ]);
                foreach (['Transaksi rental' => RentalOrder::class, 'Pesanan laundry' => LaundryOrder::class] as $label => $model) {
                    $groups[$label] = $model::with('customer')->where(function (Builder $q) use ($match, $customerMatch) {
                        $match($q, ['code']);
                        $q->orWhereHas('customer', $customerMatch);
                    })->latest()->limit(6)->get()->map(fn ($row) => [
                        'title' => $row->code, 'detail' => $row->customer?->name ?? 'Pesanan pelanggan',
                        'url' => route($row instanceof RentalOrder ? 'admin.rentals.orders.show' : 'admin.laundry.orders.show', $row),
                    ]);
                }
                $products = RetailProduct::query();
                $match($products, ['name', 'sku', 'category']);
                $groups['Produk pendukung'] = $products->orderBy('name')->limit(6)->get()->map(fn ($row) => [
                    'title' => $row->name, 'detail' => $row->sku, 'url' => route('admin.retail.edit', $row),
                ]);
            }
        }

        return view('admin.search', compact('term', 'groups'));
    }
}
