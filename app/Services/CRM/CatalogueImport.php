<?php

namespace App\Services\CRM;

use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServiceAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CatalogueImport
{
    public function manifest(): array
    {
        return json_decode(file_get_contents(database_path('data/gayatri-menu-2026.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function apply(bool $archiveExamples = false): array
    {
        if (! app()->environment('local', 'baileys-local', 'testing')) {
            throw new RuntimeException('Impor katalog ini disiapkan untuk lokal/pengujian; rilis produksi harus dilakukan terpisah.');
        }
        $manifest = $this->manifest();
        $counts = ['services_created' => 0, 'addons_created' => 0, 'rentals_created' => 0, 'retail_created' => 0, 'laundry_created' => 0, 'examples_archived' => 0];

        return DB::transaction(function () use ($manifest, $archiveExamples, $counts) {
            $source = ['source_document' => $manifest['source']['name'], 'source_sha256' => $manifest['source']['sha256'], 'import_version' => 1];
            foreach ($manifest['services'] as $row) {
                $row['catalog_metadata'] = array_replace($row['catalog_metadata'], $source);
                $record = Service::firstOrCreate(['catalog_code' => $row['catalog_code']], $row + ['is_active' => true]);
                $counts['services_created'] += (int) $record->wasRecentlyCreated;
            }
            foreach ($manifest['addon_sets'] as $kind => $addons) {
                $parents = Service::whereIn('catalog_code', collect($manifest['services'])->filter(fn ($s) => $s['category'] === ['Baby' => 'Baby (0–24 bulan)', 'Toddler' => 'Toddler (2–5 tahun)', 'Kid' => 'Kid (5–12 tahun)'][$kind]
                    && $s['catalog_metadata']['role'] === 'service' && $s['booking_mode'] === 'online')->pluck('catalog_code'))->get();
                foreach ($parents as $parent) {
                    foreach ($addons as $addon) {
                        // Do not charge again for a component explicitly included in the package.
                        if (($addon['name'] === 'Mandi Hangat' && str_contains(mb_strtolower($parent->description ?? ''), 'mandi hangat'))
                            || (str_contains($addon['name'], 'Scrub') && str_contains($parent->catalog_family ?? '', 'SPA'))
                            || (str_contains($addon['name'], 'Masker') && str_contains($parent->catalog_family ?? '', 'SPA'))) {
                            continue;
                        }
                        $extra = Service::where('catalog_code', $addon['code'])->firstOrFail();
                        $entry = ServiceAddon::firstOrCreate(['service_id' => $parent->id, 'addon_service_id' => $extra->id], [
                            'duration_minutes' => 0, 'price_adjustment' => $extra->price, 'is_active' => true, 'requires_confirmation' => true,
                        ]);
                        $counts['addons_created'] += (int) $entry->wasRecentlyCreated;
                    }
                }
            }
            foreach ($manifest['rentals'] as $row) {
                $entry = RentalProduct::firstOrCreate(['sku' => $row['sku']], [
                    'name' => $row['name'], 'category' => $row['category'], 'daily_rate' => 0, 'weekly_rate' => $row['weekly_rate'],
                    'fortnightly_rate' => $row['fortnightly_rate'], 'monthly_rate' => $row['monthly_rate'], 'deposit' => null, 'is_active' => true,
                    'turnaround_days' => 0, 'description' => 'Tarif sesuai katalog. Ketersediaan unit, deposit, kelengkapan, dan jeda perawatan dikonfirmasi admin sebelum pemesanan.',
                    'image_path' => 'catalog/gayatri-menu/rentals/'.strtolower($row['sku']).'.webp',
                    'catalog_metadata' => $source + ['source_page' => $row['source_page'], 'stock_unconfirmed' => true, 'turnaround_unconfirmed' => true],
                ]);
                $counts['rentals_created'] += (int) $entry->wasRecentlyCreated;
            }
            foreach ($manifest['retail'] as $row) {
                $entry = RetailProduct::firstOrCreate(['sku' => $row['sku']], [
                    'name' => $row['name'], 'price' => $row['price'], 'compare_at_price' => $row['compare_at_price'], 'is_active' => true,
                    'description' => 'Produk sesuai katalog Gayatri. Ketersediaan dan pemesanan dikonfirmasi admin; tidak ada pembayaran otomatis.',
                    'image_path' => 'catalog/gayatri-menu/retail/'.strtolower($row['sku']).'.webp',
                    'catalog_metadata' => $source + ['source_page' => 18, 'stock_unconfirmed' => true],
                ]);
                $counts['retail_created'] += (int) $entry->wasRecentlyCreated;
            }
            foreach ($manifest['laundry'] as $row) {
                $entry = LaundryTariff::firstOrCreate(['code' => $row['code']], [
                    'name' => $row['name'], 'price' => $row['price'], 'unit' => $row['unit'], 'audience' => 'baby', 'is_active' => true,
                    'minimum_milli' => null, 'increment_milli' => null, 'duration_hours' => null, 'requires_confirmation' => true,
                    'description' => 'Tarif sesuai katalog Gayatri Baby Laundry. Minimum, pembulatan, dan estimasi selesai dikonfirmasi admin.',
                    'catalog_metadata' => $source + ['source_page' => 23],
                ]);
                $counts['laundry_created'] += (int) $entry->wasRecentlyCreated;
            }
            if ($archiveExamples) {
                // Only explicit DEMO records and the exact bundled seed examples; never delete history.
                $counts['examples_archived'] += Service::where('is_active', true)->whereNull('catalog_code')->where(function ($q) {
                    $q->where('name', 'like', '[DEMO]%')
                        ->orWhere(fn ($q) => $q->where('name', 'Baby Spa Premium')->where('description', 'Perawatan relaksasi bayi dengan hydrotherapy dan pijat lembut.')->where('price', 250000))
                        ->orWhere(fn ($q) => $q->where('name', 'Mom Postnatal Massage')->where('description', 'Perawatan pijat ibu pasca melahirkan untuk relaksasi dan pemulihan.')->where('price', 350000));
                })->update(['is_active' => false]);
                $counts['examples_archived'] += RentalProduct::where('is_active', true)->where('name', 'like', '[DEMO]%')->update(['is_active' => false]);
                $counts['examples_archived'] += LaundryTariff::where('is_active', true)->where('code', 'like', 'DEMO-%')->where('name', 'like', '[DEMO]%')->update(['is_active' => false]);
            }
            DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
            app(AuditLogService::class)->log(null, 'catalogue.import', null, Request::create('/console/catalogue-import'), [],
                $counts + ['source_sha256' => $manifest['source']['sha256']], 'Impor katalog lokal; histori transaksi dipertahankan.');

            return $counts;
        });
    }

    public static function sourcePage(?int $page): ?string
    {
        return $page && $page <= 23 ? Storage::disk('public')->url('catalog/gayatri-menu/pages/page-'.str_pad((string) $page, 2, '0', STR_PAD_LEFT).'.webp') : null;
    }
}
