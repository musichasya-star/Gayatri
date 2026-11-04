<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(9, 0));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
    }

    private function page(bool $hero = true): LandingPageSetting
    {
        $page = LandingPageSetting::updateOrCreate(['slug' => 'home'], ['site_name' => 'Gayatri', 'is_published' => true]);
        $page->sections()->delete();
        $page->sections()->create(['type' => 'navigation', 'title' => 'Menu', 'sort_order' => 0, 'is_active' => true, 'items' => [['label' => 'Treatment', 'url' => '#services']]]);
        if ($hero) {
            $page->sections()->create(['type' => 'hero', 'title' => 'Perawatan si kecil', 'sort_order' => 1, 'is_active' => true]);
        }

        return $page;
    }

    private function product(string $name = 'Stroller', int $daily = 25000, int $deposit = 100000): RentalProduct
    {
        $product = RentalProduct::create(['sku' => Str::uuid()->toString(), 'name' => $name, 'category' => 'Bayi', 'daily_rate' => $daily, 'deposit' => $deposit, 'is_active' => true]);
        RentalUnit::create(['rental_product_id' => $product->id, 'code' => Str::uuid()->toString(), 'status' => 'ready']);

        return $product;
    }

    public function test_public_landing_connects_live_catalogue_and_cart_without_exposing_inactive_products(): void
    {
        $this->page();
        foreach (['A Stroller', 'B Kursi Makan', 'C Box Bayi', 'D Mainan'] as $name) {
            $this->product($name);
        }
        $this->product('Alat disembunyikan')->update(['is_active' => false]);

        $response = $this->get(route('landing.show'))->assertOk()
            ->assertSee('Sewa Alat Bayi')->assertSee('Pembayaran manual')
            ->assertSee(route('rental.index'))->assertSee(route('rental.cart.index'))
            ->assertSeeInOrder(['Perawatan si kecil', 'id="rental"', 'A Stroller', 'B Kursi Makan', 'C Box Bayi'], false)
            ->assertSee('25.000')->assertSee('100.000')->assertDontSee('Alat disembunyikan')->assertDontSee('D Mainan');
        $this->assertSame(1, substr_count($response->getContent(), 'id="rental"'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_rental_entry_still_renders_for_empty_catalogue_and_cms_without_hero(): void
    {
        $page = $this->page(false);
        $this->get(route('landing.show'))->assertOk()->assertSee('Katalog sedang disiapkan')->assertSee('id="rental"', false);
        $page->update(['is_published' => false]);
        $this->get(route('landing.show'))->assertNotFound();
    }

    public function test_disabled_rental_keeps_catalogue_but_removes_order_actions_and_rejects_quotes(): void
    {
        $this->page();
        $product = $this->product();
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['enabled' => false]));

        $this->get(route('landing.show'))->assertOk()->assertSee('Pemesanan rental sementara dinonaktifkan')->assertSee('Lihat Katalog Alat')->assertDontSee('Pilih Alat &amp; Pesan Sewa', false);
        $this->get(route('rental.show', $product))->assertOk()->assertDontSee('Tambah ke Keranjang')->assertDontSee('Kirim Permintaan Sewa');
        $this->postJson(route('rental.quote'), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'start_date' => '2026-10-28', 'end_date' => '2026-10-30', 'period' => 'day', 'fulfilment' => 'self'])->assertUnprocessable();
        $this->assertDatabaseCount('rental_orders', 0);
    }

    public function test_stock_change_keeps_checkout_input_and_cart_for_recovery(): void
    {
        $product = $this->product();
        $this->post(route('rental.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'start_date' => '2026-10-28',
            'end_date' => '2026-10-30', 'period' => 'day', 'fulfilment' => 'self', 'name' => 'Penyewa Uji',
            'whatsapp_number' => '081200001234', 'terms_accepted' => 1,
        ];
        $payload['quote_token'] = $this->postJson(route('rental.quote'), $payload)->assertOk()->json('token');
        $product->units()->update(['status' => 'maintenance']);

        $this->from(route('rental.cart.index'))->post(route('rental.store'), $payload)
            ->assertRedirect(route('rental.cart.index'))->assertSessionHasErrors('rental')
            ->assertSessionHas('rental_cart.'.$product->id, 1)
            ->assertSessionHasInput('name', 'Penyewa Uji')
            ->assertSessionHasInput('end_date', '2026-10-30');
        $this->get(route('rental.cart.index'))->assertOk()->assertSee('Penyewa Uji')->assertSee('Stok Stroller berubah');
        $this->assertDatabaseCount('rental_orders', 0);
    }

    public function test_guest_can_order_from_landing_and_manual_transfer_needs_admin_confirmation_and_verification(): void
    {
        $this->page();
        $stroller = $this->product();
        $chair = $this->product('Kursi Makan', 20000, 75000);
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['dp_type' => 'percent', 'dp_value' => 50, 'payment_instructions' => 'Instruksi transfer contoh dari admin.']));

        $this->get(route('landing.show'))->assertOk();
        $this->get(route('rental.index'))->assertOk()->assertSee('Pembayaran manual');
        foreach ([$stroller, $chair] as $product) {
            $this->post(route('rental.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect(route('rental.cart.index'));
        }
        $this->get(route('rental.cart.index'))->assertOk()->assertSee('Pembayaran manual setelah konfirmasi admin.');
        $data = ['items' => [['product_id' => $stroller->id, 'quantity' => 1], ['product_id' => $chair->id, 'quantity' => 1]], 'start_date' => '2026-10-28', 'end_date' => '2026-10-30', 'period' => 'day', 'fulfilment' => 'self', 'name' => 'Penyewa Website', 'whatsapp_number' => '6281234567890', 'terms_accepted' => 1];
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        $created = $this->post(route('rental.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $order = RentalOrder::sole();
        $cookieName = config('session.cookie');
        $this->withCookie($cookieName, $created->getCookie($cookieName)->getValue())
            ->post(route('rental.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('rental.receipt', $order->code));
        $this->assertDatabaseCount('rental_orders', 1);
        $this->assertSame('pending', $order->status);
        $this->assertSame(2, $order->items()->count());
        $this->assertEquals(310000, $order->balance());
        $this->assertEquals(67500, $order->payment_terms['minimum_dp']);
        $this->assertEmpty(session('rental_cart'));
        $this->assertGuest();
        $this->get(route('rental.receipt', $order->code))->assertOk()->assertSee('Pembayaran manual')->assertSee('Lihat Pembayaran di Portal');
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Tunggu konfirmasi pesanan dari admin sebelum melakukan pembayaran.')->assertSee('Instruksi transfer contoh dari admin.')->assertDontSee('Kirim Bukti untuk Verifikasi');
        $this->assertDatabaseCount('rental_payments', 0);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();
        $this->app['auth']->guard('web')->logout();
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Kirim Bukti untuk Verifikasi');
        $this->post(route('rental.portal.proof', $order->code), ['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 67500, 'payer_name' => 'Penyewa Website', 'payment_date' => '2026-10-28', 'proof' => UploadedFile::fake()->image('bukti-transfer.jpg')])->assertSessionHasNoErrors();
        $proof = $order->paymentProofs()->sole();
        $this->assertSame('pending', $proof->status);
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Bukti sedang diperiksa')->assertDontSee('Kirim Bukti untuk Verifikasi');
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertEquals(310000, $order->fresh()->balance());

        $this->actingAs($admin)->post(route('admin.rentals.proofs.review', [$order, $proof]), ['action' => 'approve', 'review_notes' => 'Dana simulasi dicocokkan dengan transfer uji.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $proof->fresh()->status);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertEquals(242500, $order->fresh()->balance());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
