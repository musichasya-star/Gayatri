<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Branch;
use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\Service;
use App\Services\AppSettingService;
use App\Services\CRM\RentalSettings;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicServicePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(string $name, array $extra = []): Service
    {
        return Service::create(array_replace(['name' => $name, 'catalog_family' => $name, 'variant_label' => 'Reguler', 'category' => 'Perawatan', 'service_group' => 'spa', 'price' => 40000, 'duration_minutes' => 30, 'booking_mode' => 'online', 'is_active' => true], $extra));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }

    public function test_catalogue_groups_variants_and_filters_group_kind_and_readiness(): void
    {
        $this->service('Pijat Reguler', ['catalog_family' => 'Pijat keluarga']);
        $this->service('Pijat Private', ['catalog_family' => 'Pijat keluarga', 'variant_label' => 'Private', 'price' => 90000]);
        $this->service('Kelas Menyusui', ['service_group' => 'lactation', 'category' => 'Kelas ASI', 'catalog_metadata' => ['kind' => 'class']]);
        $this->service('Paket Pijat', ['catalog_metadata' => ['role' => 'bundle', 'package_quantity' => 3]]);
        $this->service('Tidak dipublikasikan', ['is_active' => false]);
        $this->service('[DEMO] Perawatan contoh');
        $this->get('/layanan?group=spa&kind=treatment&readiness=ready')->assertOk()->assertSee('Pijat keluarga')->assertSee('Private')
            ->assertDontSee('Tidak dipublikasikan')->assertDontSee('[DEMO] Perawatan contoh')->assertDontSee('Paket Pijat')
            ->assertViewHas('families', fn ($rows) => $rows->total() === 1 && $rows->first()->count() === 2);
        $this->get('/layanan?group=spa&kind=package&readiness=admin')->assertOk()->assertSee('Paket Pijat')->assertSee('data-admin="1"', false)->assertDontSee('data-admin="0"', false);
        $this->get('/layanan?group=lactation&kind=class')->assertOk()->assertSee('Kelas Menyusui')->assertDontSee('Pijat keluarga');
        $this->get('/layanan?group=lactation&category=Perawatan')->assertOk()->assertSee('Kelas Menyusui')->assertViewHas('filters', fn ($filters) => ! isset($filters['category']));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_variant_selection_and_filters_survive_a_round_trip_to_booking(): void
    {
        $this->service('Pijat Reguler', ['catalog_family' => 'Pijat keluarga']);
        $private = $this->service('Pijat Private', ['catalog_family' => 'Pijat keluarga', 'variant_label' => 'Private', 'price' => 90000]);
        $response = $this->get('/layanan?group=spa&kind=treatment&search=Pijat&selected_service='.$private->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $selected = $xpath->query('//select[@data-offer-select]/option[@selected]')->item(0);
        $this->assertSame((string) $private->id, $selected->getAttribute('value'));
        $href = $xpath->query('//a[@data-offer-action]')->item(0)->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame((string) $private->id, $query['service_id']);
        $this->assertSame('booking-form', parse_url($href, PHP_URL_FRAGMENT));
        parse_str(parse_url($query['return_to'], PHP_URL_QUERY), $returnFilters);
        $this->assertSame('Pijat', $returnFilters['search']);
        $this->assertSame('treatment', $returnFilters['kind']);
        $booking = $this->get($href)->assertOk();
        $xpath = $this->xpath($booking->getContent());
        $this->assertSame((string) $private->id, $xpath->query('//select[@name="service_id"]/option[@selected]')->item(0)->getAttribute('value'));
        $back = $xpath->query('//a[@data-return-catalogue]')->item(0)->getAttribute('href');
        $this->assertSame('/layanan', parse_url($back, PHP_URL_PATH));
        $this->get($back)->assertOk()->assertSee('Private');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_public_back_links_reject_external_urls_and_malformed_parameters(): void
    {
        foreach (['https://untrusted.example/layanan', '//untrusted.example/layanan', 'javascript:alert(1)', '/admin/customers', '/layanan?search[]=unsafe'] as $target) {
            $response = $this->get('/booking?service_group=spa&return_to='.rawurlencode($target))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $href = $xpath->query('//a[@data-return-catalogue]')->item(0)->getAttribute('href');
            $this->assertSame('http://localhost/layanan?group=spa', $href);
        }
    }

    public function test_pagination_keeps_catalogue_filters_and_empty_results_offer_recovery(): void
    {
        foreach (range(1, 14) as $n) {
            $this->service('Pijat '.$n);
        }
        $response = $this->get('/layanan?group=spa&kind=treatment&search=Pijat')->assertOk();
        $xpath = $this->xpath($response->getContent());
        $next = $xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('spa', $query['group']);
        $this->assertSame('Pijat', $query['search']);
        $this->assertSame('2', $query['page']);
        $this->get($next)->assertOk()->assertViewHas('families', fn ($rows) => $rows->count() === 2);
        $this->get('/layanan?search=TidakDitemukan')->assertOk()->assertSee('Belum ada pilihan yang sesuai.')->assertSee('Hapus filter');
    }

    public function test_demo_and_inactive_branches_are_not_offered_or_accepted_for_public_booking(): void
    {
        $good = Branch::create(['name' => 'Outlet aktif', 'code' => 'KDR', 'status' => 'active']);
        $demo = Branch::create(['name' => 'Cabang percobaan', 'code' => 'DEMO-01', 'status' => 'active']);
        $closed = Branch::create(['name' => 'Outlet ditutup', 'code' => 'KDR-OFF', 'status' => 'inactive']);
        Branch::create(['name' => 'Gayatri Mom & Baby SPA Jakarta', 'code' => 'GAY-JKT', 'address' => 'Jl. Mawar No. 10, Jakarta Selatan', 'phone' => '0215550123', 'status' => 'active']);
        $service = $this->service('Perawatan aktif');
        $slot = AvailabilitySlot::create(['branch_id' => $demo->id, 'service_id' => $service->id, 'slot_date' => today()->addDay(), 'start_time' => '10:00', 'end_time' => '10:30', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        $this->get('/booking?service_group=spa')->assertOk()->assertViewHas('branches', fn ($items) => $items->modelKeys() === [$good->id])
            ->assertViewHas('availabilitySlots', fn ($items) => $items->isEmpty());
        $this->post(route('landing.booking'), ['name' => 'Bunda Uji', 'whatsapp_number' => '081122233344', 'service_group' => 'spa', 'service_id' => $service->id, 'branch_id' => $demo->id, 'availability_slot_id' => $slot->id, 'booking_date' => today()->addDay()->toDateString(), 'start_time' => '10:00'])->assertSessionHasErrors();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('customers', 0);
        Queue::assertNothingPushed();
    }

    public function test_rental_dates_and_search_survive_detail_and_back_navigation(): void
    {
        $product = RentalProduct::create(['sku' => 'FC-STROLLER', 'name' => 'Stroller uji', 'category' => 'Perlengkapan', 'daily_rate' => 20000, 'deposit' => 100000, 'is_active' => true]);
        RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'FC-UNIT', 'status' => 'ready']);
        $start = today()->addDay()->toDateString();
        $end = today()->addDays(3)->toDateString();
        $response = $this->get('/rental?search=Stroller&start_date='.$start.'&end_date='.$end)->assertOk()->assertSee('1 unit tersedia pada tanggal pilihan');
        $xpath = $this->xpath($response->getContent());
        $href = $xpath->query('//article//a')->item(0)->getAttribute('href');
        $detail = $this->get($href)->assertOk()->assertSee('Tambah ke Keranjang')->assertSee('value="'.$start.'"', false)->assertSee('value="'.$end.'"', false);
        $xpath = $this->xpath($detail->getContent());
        $back = $xpath->query('//nav[@aria-label="Jejak halaman"]/a[2]')->item(0)->getAttribute('href');
        parse_str(parse_url($back, PHP_URL_QUERY), $query);
        $this->assertSame('Stroller', $query['search']);
        $this->assertSame($start, $query['start_date']);
        $this->get($back)->assertOk();
        $this->assertDatabaseCount('rental_orders', 0);
    }

    public function test_unready_rental_and_closed_rental_do_not_offer_checkout_forms(): void
    {
        $product = RentalProduct::create(['sku' => 'FC-EMPTY', 'name' => 'Alat tanpa unit', 'category' => 'Perlengkapan', 'daily_rate' => 0, 'weekly_rate' => 45000, 'deposit' => null, 'is_active' => true]);
        $this->get(route('rental.show', $product))->assertOk()->assertSee('Konfirmasi alat ke admin')->assertDontSee('data-rental-checkout', false)->assertDontSee('Tambah ke Keranjang');
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['enabled' => false]));
        $this->get('/rental')->assertOk()->assertSee('Pemesanan rental sementara dinonaktifkan');
        $this->get('/layanan?type=rental')->assertOk()->assertSee('Pemesanan belum dibuka');
        $this->get(route('rental.show', $product))->assertOk()->assertSee('Pemesanan sementara dinonaktifkan')->assertDontSee('data-rental-checkout', false);
    }

    public function test_laundry_tariffs_remain_visible_while_closed_and_form_requires_complete_outlet(): void
    {
        LaundryTariff::create(['name' => 'Baju bayi uji', 'audience' => 'baby', 'code' => 'FC-LAUNDRY', 'unit' => 'kg', 'price' => 15000, 'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 48, 'is_active' => true, 'requires_confirmation' => false]);
        $settings = app(AppSettingService::class);
        $settings->set('laundry.public', json_encode(['enabled' => true]));
        $settings->set('laundry.outlet', json_encode(['accepting_orders' => true, 'address' => '', 'whatsapp_number' => '']));
        $this->get('/laundry')->assertOk()->assertSee('Pemesanan online belum dibuka')->assertSee('Baju bayi uji')->assertSee('15.000')->assertDontSee('data-public-laundry', false);
        $this->get('/layanan?type=laundry')->assertOk()->assertSee('Informasi Laundry')->assertDontSee('Pesan laundry');
        $settings->set('laundry.outlet', json_encode(['accepting_orders' => true, 'address' => 'Alamat outlet pengujian', 'whatsapp_number' => '6281122233344']));
        $this->get('/laundry')->assertOk()->assertSee('data-public-laundry', false)->assertSee('Konfirmasi hasil timbang')->assertDontSee('Pemesanan online belum dibuka');
        $this->assertDatabaseCount('laundry_orders', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
