<?php

namespace App\Services\CRM;

use App\Models\AvailabilitySlot;
use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\Service;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiKnowledgeReadiness;
use App\Services\Web\PublicCatalogue;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\GatewayHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Observes readiness without changing business settings or creating transactions. */
class OperationalReadiness
{
    public const EVIDENCE_PATH = 'operations/verification.json';

    public const LABELS = ['ready' => 'Siap', 'attention' => 'Perlu dilengkapi', 'blocked' => 'Belum siap', 'unverified' => 'Belum diverifikasi'];

    public function report(): array
    {
        $checks = [];
        $add = function (string $id, string $title, string $status, string $detail, string $route, array $params = []) use (&$checks) {
            $checks[$id] = compact('id', 'title', 'status', 'detail', 'route', 'params');
        };
        $health = app(GatewayHealth::class)->inspect();
        $session = (string) GatewayConfig::get('default_session');
        $working = $health['reachable'] && collect($health['sessions'])->contains(fn ($row) => $row['name'] === $session && in_array($row['status'], ['WORKING', 'CONNECTED'], true));
        $add('whatsapp', 'Koneksi WhatsApp', $working ? 'ready' : 'blocked', $working ? 'Sesi utama terhubung. Status diperiksa '.$health['checked_at'].' WIB.' : 'Sesi utama belum terkonfirmasi terhubung. Periksa gateway dan pindai QR bila diperlukan.', 'admin.whatsapp.session');

        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $connection = (string) config('queue.default');
        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');
        $seen = null;
        $alive = false;
        try {
            $seen = Cache::get('whatsapp:worker:'.hash('sha256', $connection.'|'.$queue));
            $alive = $seen && Carbon::parse($seen)->betweenIncluded(now()->subMinutes(3), now()->addSeconds(30));
        } catch (Throwable) {
            // A malformed heartbeat cannot mark a worker as healthy.
        }
        $webhooks = $health['webhooks_pending'];
        $add('queue', 'Antrean dan worker', $failed || ! $alive ? 'attention' : 'ready', "$pending pekerjaan dalam antrean · $failed gagal. ".($alive ? 'Worker terdeteksi aktif.' : 'Heartbeat worker belum terdeteksi.').($webhooks !== null ? " $webhooks webhook menunggu." : ''), 'admin.whatsapp.monitor');
        if ($webhooks > 0 && $checks['queue']['status'] === 'ready') {
            $checks['queue']['status'] = 'attention';
        }

        $provider = (string) config('ai.provider', 'local');
        $configured = $provider !== 'local' && filled(config('ai.api_key')) && filled(config('ai.model'));
        $policy = app(AiInteractionPolicy::class)->snapshot();
        $add('ai', 'Konfigurasi AI dan balasan otomatis', $configured && $policy['enabled'] ? 'ready' : 'attention', ($configured ? 'Provider '.ucfirst($provider).' dan model '.config('ai.model').' tersimpan.' : 'Provider eksternal atau API key belum lengkap.').' '.($policy['enabled'] ? 'Balasan otomatis aktif.' : 'Balasan otomatis belum aktif.'), 'admin.settings.index');

        $knowledge = app(AiKnowledgeReadiness::class)->report();
        $readyDomains = collect($knowledge['domains'])->filter(fn ($row) => $row['published_usable'] > 0 && ! $row['topic_gaps'])->count();
        $add('knowledge', 'Panduan jawaban pelanggan', $knowledge['ready_for_live_faq'] ? 'ready' : 'attention', "$readyDomains dari 5 kelompok informasi memiliki panduan terbit dan cakupan topik lengkap. Draf dan contoh demo perlu ditinjau sebelum digunakan pelanggan.", 'admin.ai.knowledge.index');

        $branches = PublicCatalogue::branches()->count();
        $add('branches', 'Cabang untuk pemesanan publik', $branches ? 'ready' : 'blocked', "$branches cabang aktif dapat ditampilkan kepada pelanggan. Lengkapi alamat dan kontak outlet sebenarnya.", 'admin.branches.index');
        foreach (['spa' => 'Pijat & Spa', 'lactation' => 'Laktasi & ASI'] as $group => $label) {
            $services = Service::where('is_active', true)->where('service_group', $group)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])->get();
            $counts = $services->countBy(fn ($service) => $service->readiness());
            $bookable = PublicCatalogue::bookableServices()->where('service_group', $group)->count();
            $add('catalogue_'.$group, 'Katalog '.$label, ($counts['incomplete'] ?? 0) || ! $bookable ? 'attention' : 'ready', ($counts['ready'] ?? 0).' variasi lengkap · '.($counts['admin'] ?? 0).' melalui admin · '.($counts['incomplete'] ?? 0)." perlu dilengkapi. $bookable variasi memenuhi syarat pemesanan publik.", 'admin.services.index', ['service_group' => $group]);
            $slots = AvailabilitySlot::where('status', 'available')->whereColumn('booked_count', '<', 'capacity')
                ->where(fn ($q) => $q->whereDate('slot_date', '>', today())->orWhere(fn ($q) => $q->whereDate('slot_date', today())->where('start_time', '>', now()->format('H:i:s'))))
                ->whereIn('service_id', PublicCatalogue::bookableServices()->where('service_group', $group)->select('id'))
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', PublicCatalogue::branches()->select('id')))->count();
            $add('slots_'.$group, 'Jadwal '.$label, $slots ? 'ready' : 'blocked', "$slots slot mendatang masih dapat dipesan. Isi jadwal sesuai kesiapan terapis; slot penuh, diblokir, lampau, dan cabang demo tidak dihitung.", 'admin.availability.index');
        }

        $products = RentalProduct::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])->withCount(['units', 'units as ready_units_count' => fn ($q) => $q->where('status', 'ready')])->get();
        $rentalCounts = $products->countBy(fn ($product) => $product->catalogueReadiness());
        $rental = app(RentalSettings::class)->get();
        $add('rental', 'Katalog dan unit rental', ! $rental['enabled'] || ! ($rentalCounts['ready'] ?? 0) ? 'blocked' : (($rentalCounts['incomplete'] ?? 0) ? 'attention' : 'ready'), ($rentalCounts['ready'] ?? 0).' produk siap · '.($rentalCounts['incomplete'] ?? 0).' perlu tarif/deposit/jeda pembersihan · '.($rentalCounts['empty'] ?? 0).' tanpa unit · '.($rentalCounts['busy'] ?? 0).' sedang digunakan/perawatan. '.($rental['enabled'] ? 'Pemesanan rental aktif.' : 'Pemesanan rental belum aktif.'), 'admin.rentals.products.index');

        $outlet = app(LaundrySettings::class)->get();
        $public = app(LaundryPublicSettings::class)->get();
        $tariffs = LaundryTariff::where('is_active', true)->get()->countBy(fn ($tariff) => $tariff->catalogueReadiness());
        $laundryReady = $outlet['accepting_orders'] && $public['enabled'] && filled($outlet['address']) && filled($outlet['whatsapp_number']) && ($tariffs['ready'] ?? 0) > 0;
        $add('laundry', 'Pemesanan Laundry', $laundryReady ? (($tariffs['incomplete'] ?? 0) ? 'attention' : 'ready') : 'blocked', ($tariffs['ready'] ?? 0).' tarif siap · '.($tariffs['incomplete'] ?? 0).' perlu minimum/pembulatan/estimasi. '.($outlet['accepting_orders'] ? 'Penerimaan outlet aktif.' : 'Penerimaan outlet belum aktif.').' '.($public['enabled'] ? 'Pemesanan web aktif.' : 'Pemesanan web belum aktif.'), 'admin.laundry.settings');
        $add('laundry_payment', 'Instruksi pembayaran Laundry', filled($public['bank_name']) && filled($public['account_number']) && filled($public['account_holder']) ? 'ready' : 'attention', 'Periksa rekening tujuan dan instruksi pembayaran manual yang ditampilkan pada portal pelanggan.', 'admin.laundry.requests.settings');
        $add('rental_payment', 'Instruksi pembayaran rental', filled($rental['payment_instructions']) ? 'ready' : 'attention', 'Lengkapi instruksi pembayaran, aturan DP, dan waktu pelunasan sesuai kebijakan usaha.', 'admin.rentals.settings.index');

        $home = app(HomeService::class)->settings();
        $add('home_service', 'Home Service', ! $home['enabled'] ? 'unverified' : (filled($home['office_address']) && $home['latitude'] !== null && $home['longitude'] !== null && $home['rate_per_km'] !== null && $home['max_distance_km'] > 0 && $home['service_ids'] && $home['therapist_ids'] ? 'ready' : 'attention'), $home['enabled'] ? 'Periksa titik kantor, ongkos per kilometer, jarak layanan, dan terapis yang bertugas.' : 'Layanan kunjungan rumah belum diaktifkan. Aktifkan bila tim sudah siap.', 'admin.home-service.index');
        $mail = (string) config('mail.default');
        $mailReady = ! in_array($mail, ['log', 'array', '', 'failover'], true) && filled(config('mail.from.address'));
        $add('email', 'Email pemulihan akun', $mailReady ? 'unverified' : 'attention', $mailReady ? 'Transport pengiriman tersedia; penerimaan email masih perlu diuji.' : 'Email masih memakai pencatatan lokal. Konfigurasi pengiriman diperlukan agar tautan pemulihan diterima pengguna.', 'admin.settings.index');

        return ['checked_at' => now()->toIso8601String(), 'checks' => $checks, 'counts' => collect($checks)->countBy('status')->all(), 'knowledge' => $knowledge['domains'], 'evidence' => $this->evidence()];
    }

    /** Return a small whitelist only: no raw exception, secret, phone, or customer record. */
    public function evidence(): array
    {
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::EVIDENCE_PATH)) {
                return [];
            }
            $stored = json_decode($disk->get(self::EVIDENCE_PATH), true, 16, JSON_THROW_ON_ERROR);
            $rows = [];
            foreach (['ai_connection' => 'Koneksi provider AI', 'transactions' => 'Alur transaksi terisolasi', 'whatsapp_delivery' => 'Pengiriman WhatsApp ke nomor uji', 'backup_restore' => 'Pemulihan backup terisolasi'] as $key => $label) {
                $row = $stored[$key] ?? null;
                if (! is_array($row) || ! is_string($row['checked_at'] ?? null)) {
                    continue;
                }
                $at = Carbon::parse($row['checked_at']);
                $fresh = $at->betweenIncluded(now()->subDay(), now()->addMinute());
                $matches = $key !== 'ai_connection' || hash_equals($this->aiFingerprint(), (string) ($row['fingerprint'] ?? ''));
                $rows[] = ['label' => $label, 'passed' => ($row['passed'] ?? false) === true, 'checked_at' => $at->timezone(config('app.timezone'))->format('d M Y H:i'), 'current' => $fresh && $matches];
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public function aiFingerprint(): string
    {
        return hash('sha256', config('ai.provider').'|'.config('ai.model').'|'.hash('sha256', (string) config('ai.api_key')));
    }

    public function missingRows(): iterable
    {
        foreach (Service::where('is_active', true)->orderBy('id')->cursor() as $service) {
            if ($service->readiness() === 'incomplete') {
                yield ['Layanan', $service->catalog_code ?: $service->id, $service->name, $service->readinessReason(), route('admin.services.edit', $service)];
            }
        }
        foreach (RentalProduct::where('is_active', true)->withCount(['units', 'units as ready_units_count' => fn ($q) => $q->where('status', 'ready')])->get() as $product) {
            if ($product->catalogueReadiness() !== 'ready') {
                yield ['Rental', $product->sku, $product->name, $product->catalogueMissing().($product->units_count ? '' : ' Belum ada unit fisik.'), route('admin.rentals.products.edit', $product)];
            }
        }
        foreach (LaundryTariff::where('is_active', true)->get() as $tariff) {
            if ($tariff->catalogueReadiness() !== 'ready') {
                yield ['Laundry', $tariff->code, $tariff->name, $tariff->catalogueMissing(), route('admin.laundry.tariffs.edit', $tariff)];
            }
        }
    }
}
