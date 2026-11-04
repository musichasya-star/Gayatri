<?php

namespace App\Services\CRM;

use App\Models\RentalOrder;
use App\Models\RentalProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class RentalQuote
{
    public function __construct(private readonly RentalSettings $settings, private readonly RentalInventory $inventory, private readonly HomeService $routing) {}

    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:rental_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'period' => ['required', Rule::in(array_keys(RentalOrder::PERIODS))],
            'fulfilment' => ['required', Rule::in(array_keys(RentalOrder::FULFILMENTS))],
            'address' => ['nullable', 'required_unless:fulfilment,self', 'string', 'min:10', 'max:2000'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'required_unless:fulfilment,self', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_unless:fulfilment,self', 'numeric', 'between:-180,180'],
        ];
    }

    public function calculate(array $data): array
    {
        $settings = $this->settings->get();
        if (! $settings['enabled']) {
            throw new InvalidArgumentException('Pemesanan rental sedang dinonaktifkan admin.');
        }
        $days = (int) Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;
        if ($days > $settings['max_rental_days'] || array_sum(array_column($data['items'], 'quantity')) > 20) {
            throw new InvalidArgumentException('Maksimal '.$settings['max_rental_days'].' hari dan 20 unit per transaksi.');
        }
        $blocks = (int) ceil($days / match ($data['period']) {
            'week' => 7, 'fortnight' => 14, 'month' => 30, default => 1
        });
        $rows = [];
        foreach ($data['items'] as $row) {
            $product = RentalProduct::where('is_active', true)->find($row['product_id']);
            if (! $product) {
                throw new InvalidArgumentException('Alat tidak tersedia dalam katalog aktif.');
            }
            if ($product->requiresConfirmation()) {
                throw new InvalidArgumentException($product->name.': deposit dan kesiapan unit perlu dikonfirmasi admin sebelum pemesanan.');
            }
            $available = $this->inventory->available($product, $data['start_date'], $data['end_date'])->count();
            if ($available < $row['quantity']) {
                throw new InvalidArgumentException($product->name.': tersedia '.$available.' unit pada tanggal tersebut.');
            }
            $rate = $product->{match ($data['period']) {
                'week' => 'weekly_rate', 'fortnight' => 'fortnightly_rate', 'month' => 'monthly_rate', default => 'daily_rate'
            }};
            if ($rate === null || (float) $rate <= 0) {
                throw new InvalidArgumentException('Tarif periode yang dipilih belum tersedia untuk '.$product->name.'.');
            }
            $rows[] = ['product_id' => $product->id, 'name' => $product->name, 'quantity' => (int) $row['quantity'], 'rate' => (int) $rate,
                'unit_price' => (int) $rate * $blocks, 'unit_deposit' => (int) $product->deposit,
                'components' => $product->components, 'terms' => $product->terms, 'turnaround_days' => $product->turnaround_days];
        }
        $route = null;
        $deliveryFee = $collectionFee = 0;
        if ($data['fulfilment'] !== 'self') {
            if (! $settings['delivery_enabled'] || $settings['latitude'] === null || $settings['longitude'] === null) {
                throw new InvalidArgumentException('Layanan antar-jemput belum diaktifkan atau titik kantor belum diisi admin.');
            }
            $destination = ['latitude' => round((float) $data['latitude'], 7), 'longitude' => round((float) $data['longitude'], 7)];
            $key = 'rental-route:'.hash('sha256', json_encode([$settings['latitude'], $settings['longitude'], $destination, $settings['routing_provider'], $settings['routing_base_url']]));
            $route = Cache::remember($key, now()->addMinutes(10), fn () => $this->routing->route($settings, $destination));
            if ($route['distance_meters'] > (float) $settings['max_distance_km'] * 1000) {
                throw new InvalidArgumentException('Alamat di luar jangkauan antar-jemput rental (maksimal '.$settings['max_distance_km'].' km).');
            }
            $billable = (int) ceil(max(0, $route['distance_meters'] - (float) $settings['free_km'] * 1000) / 1000);
            $route['billable_km'] = $billable;
            if (in_array($data['fulfilment'], ['delivery', 'both'], true)) {
                $deliveryFee = (int) $settings['delivery_base_fee'] + $billable * (int) $settings['delivery_rate_per_km'];
            }
            if (in_array($data['fulfilment'], ['collection', 'both'], true)) {
                $collectionFee = (int) $settings['collection_base_fee'] + $billable * (int) $settings['collection_rate_per_km'];
            }
        }
        $subtotal = array_sum(array_map(fn ($row) => $row['unit_price'] * $row['quantity'], $rows));
        $deposit = array_sum(array_map(fn ($row) => $row['unit_deposit'] * $row['quantity'], $rows));
        $policy = app(RentalPolicy::class)->snapshot($settings, $rows);
        $rows = array_map(function ($row) {
            unset($row['terms']);

            return $row;
        }, $rows);
        $quote = ['id' => (string) Str::uuid(), 'input_hash' => $this->inputHash($data), 'config_hash' => $this->settings->fingerprint(),
            'expires_at' => now()->addMinutes(15)->timestamp, 'days' => $days, 'period_count' => $blocks, 'rows' => $rows,
            'rental_subtotal' => $subtotal, 'deposit_total' => $deposit, 'delivery_fee' => $deliveryFee, 'collection_fee' => $collectionFee,
            'total' => $subtotal + $deposit + $deliveryFee + $collectionFee, 'route' => $route,
            'office_name' => $settings['office_name'], 'office_address' => $settings['office_address'],
            'payment_terms' => app(RentalPaymentTerms::class)->snapshot($settings, $subtotal + $deliveryFee + $collectionFee),
            'terms_snapshot' => $policy,
            'logistics_settings' => array_intersect_key($settings, array_flip(['latitude', 'longitude', 'delivery_rate_per_km', 'delivery_base_fee', 'collection_rate_per_km', 'collection_base_fee', 'free_km', 'max_distance_km', 'routing_provider']))];

        return ['quote' => $quote, 'token' => Crypt::encryptString(json_encode($quote, JSON_UNESCAPED_UNICODE))];
    }

    public function decode(string $token, array $data): array
    {
        try {
            $quote = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if ($quote['expires_at'] < now()->timestamp || $quote['input_hash'] !== $this->inputHash($data) || $quote['config_hash'] !== $this->settings->fingerprint()) {
                throw new InvalidArgumentException;
            }

            return $quote;
        } catch (Throwable) {
            throw new InvalidArgumentException('Cek ketersediaan dan hitung ulang total sebelum menyimpan. Estimasi berlaku 15 menit.');
        }
    }

    private function inputHash(array $data): string
    {
        $items = collect($data['items'])->map(fn ($row) => ['product_id' => (int) $row['product_id'], 'quantity' => (int) $row['quantity']])->sortBy('product_id')->values()->all();

        return hash('sha256', json_encode([$items, $data['start_date'], $data['end_date'], $data['period'], $data['fulfilment'],
            $data['fulfilment'] === 'self' ? null : round((float) $data['latitude'], 7), $data['fulfilment'] === 'self' ? null : round((float) $data['longitude'], 7)]));
    }
}
