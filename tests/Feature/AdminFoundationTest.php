<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Promo;
use App\Models\RentalOrder;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function actor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function customer(string $name = 'Bunda Temu'): Customer
    {
        return Customer::create(['name' => $name, 'whatsapp_number' => '62812'.random_int(10000000, 99999999), 'status' => 'lead']);
    }

    private function booking(Customer $customer, Service $service, string $code = 'BK-TEMU'): Booking
    {
        return Booking::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => $code,
            'booking_date' => today()->addDay(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'draft', 'payment_status' => 'unpaid', 'source' => 'manual', 'promo_discount' => 12345]);
    }

    public function test_legacy_rental_totals_render_without_rewriting_current_or_original_finances(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->actor());
        foreach ([[], ['rental_subtotal' => 75000, 'deposit_total' => 100000, 'delivery_fee' => 15000, 'collection_fee' => 10000], ['total' => 0], ['total' => 250000]] as $index => $snapshot) {
            $order = RentalOrder::create(['code' => 'RENT-OLD-'.$index, 'quote_id' => 'old-'.$index, 'request_session_hash' => str_repeat('a', 64), 'customer_id' => $customer->id,
                'source' => 'admin', 'status' => 'completed', 'start_date' => today()->subDays(5), 'end_date' => today()->subDays(2), 'period' => 'day', 'period_count' => 3,
                'fulfilment' => 'self', 'rental_subtotal' => 300000, 'deposit_total' => 100000, 'delivery_fee' => 15000, 'collection_fee' => 10000, 'pricing_snapshot' => $snapshot]);
            $before = $order->refresh()->getRawOriginal();
            $this->assertSame([null, 200000.0, 0.0, 250000.0][$index], $order->originalTotal());
            $this->get(route('admin.rentals.orders.show', $order))->assertOk();
            $this->get(route('admin.rentals.orders.index'))->assertOk()->assertSee('Total awal belum tercatat pada data lama.')->assertSee('Tagihan saat ini Rp 325.000');
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertDatabaseCount('rental_documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_global_search_finds_authorized_records_without_marking_chat_read_or_changing_booking(): void
    {
        $actor = $this->actor();
        $customer = $this->customer();
        $service = Service::create(['name' => 'Layanan Temu', 'price' => 200000, 'duration_minutes' => 60, 'is_active' => true]);
        $booking = $this->booking($customer, $service);
        $before = $booking->refresh()->getRawOriginal();
        $campaign = Campaign::create(['name' => 'Campaign Temu', 'message_template' => 'Rahasia isi campaign', 'status' => 'draft']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => $customer->whatsapp_number.'@c.us', 'unread_count' => 3, 'status' => 'open']);

        $this->actingAs($actor)->get(route('admin.search', ['q' => 'Temu']))->assertOk()
            ->assertSee(route('admin.customers.show', $customer), false)->assertSee(route('admin.bookings.edit', $booking), false)
            ->assertSee(route('admin.services.edit', $service), false)->assertSee(route('admin.campaigns.edit', $campaign), false)
            ->assertSee(route('admin.inbox', ['conversation' => $conversation->id]), false)->assertDontSee('Rahasia isi campaign')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame(3, $conversation->fresh()->unread_count);
        $this->assertSame($before, $booking->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    public function test_search_enforces_therapist_assignment_and_branch_on_every_match(): void
    {
        $therapist = $this->actor('therapist');
        $branch = Branch::create(['name' => 'Cabang Temu', 'code' => 'TEMU', 'status' => 'active']);
        Therapist::create(['user_id' => $therapist->id, 'branch_id' => $branch->id, 'name' => 'Terapis Temu', 'status' => 'active']);
        foreach (['Temu Milik Sendiri', 'Temu Bukan Tugas', 'Temu Cabang Lain'] as $index => $name) {
            $customer = $this->customer($name);
            $customer->update(['branch_id' => $index === 2 ? null : $branch->id]);
            Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => $customer->whatsapp_number.'@c.us', 'assigned_user_id' => $index === 1 ? null : $therapist->id, 'status' => 'open']);
        }
        $service = Service::create(['name' => 'Temu Layanan Tersembunyi', 'price' => 200000, 'duration_minutes' => 60, 'is_active' => true]);
        $this->actingAs($therapist)->get(route('admin.search', ['q' => 'Temu']))->assertOk()
            ->assertSee('Temu Milik Sendiri')->assertDontSee('Temu Bukan Tugas')->assertDontSee('Temu Cabang Lain')->assertDontSee($service->name)
            ->assertDontSee(route('admin.ai.knowledge.index'), false)->assertDontSee(route('admin.settings.index'), false)->assertDontSee(route('admin.customers.index'), false);
        $therapist->therapistProfile->update(['status' => 'inactive']);
        $this->get(route('admin.search', ['q' => 'Temu']))->assertOk()->assertDontSee('Temu Milik Sendiri');
    }

    public function test_sales_search_does_not_expose_booking_or_catalog_admin_destinations(): void
    {
        $sales = $this->actor('sales');
        $customer = $this->customer();
        $service = Service::create(['name' => 'Temu Layanan Privat', 'price' => 200000, 'duration_minutes' => 60, 'is_active' => true]);
        $booking = $this->booking($customer, $service, 'BK-TEMU-PRIVATE');
        $this->actingAs($sales)->get(route('admin.search', ['q' => 'Temu']))->assertOk()->assertSee($customer->name)
            ->assertDontSee($booking->booking_code)->assertDontSee($service->name)
            ->assertDontSee(route('admin.bookings.index'), false)->assertDontSee(route('admin.users.index'), false)->assertDontSee(route('admin.settings.index'), false);
    }

    public function test_search_limits_results_and_matches_wildcards_literally(): void
    {
        $this->actingAs($this->actor());
        $literal = $this->customer('Bunda %_! Literal');
        for ($i = 0; $i < 8; $i++) {
            $this->customer('Bunda Banyak '.$i);
        }
        $response = $this->get(route('admin.search', ['q' => '%_!']))->assertOk()->assertSee($literal->name)->assertDontSee('Bunda Banyak');
        $this->get(route('admin.search', ['q' => 'Bunda Banyak']))->assertOk()->assertViewHas('groups', fn ($groups) => $groups['Pelanggan']->count() === 6);
        $this->getJson(route('admin.search', ['q' => 'a']))->assertUnprocessable();
        $this->getJson(route('admin.search', ['q' => ['wrong']]))->assertUnprocessable();
        $this->getJson(route('admin.search', ['q' => str_repeat('a', 101)]))->assertUnprocessable();
        $this->get(route('admin.search'))->assertOk()->assertSee('Masukkan sedikitnya dua karakter');
    }

    public function test_search_requires_an_active_authenticated_user(): void
    {
        $this->get(route('admin.search', ['q' => 'Bunda']))->assertRedirect(route('login'));
        $actor = $this->actor();
        $actor->update(['status' => 'inactive']);
        $this->actingAs($actor)->get(route('admin.search', ['q' => 'Bunda']))->assertRedirect(route('login'));
    }

    public function test_legacy_percentage_promo_renders_and_applies_correctly_without_repricing_existing_booking(): void
    {
        $admin = $this->actor();
        $branch = Branch::create(['name' => 'Cabang Promo', 'code' => 'PRM', 'status' => 'active']);
        $customer = $this->customer();
        $service = Service::create(['name' => 'Treatment Promo', 'branch_id' => $branch->id, 'duration_minutes' => 60, 'price' => 200000, 'is_active' => true]);
        $promo = Promo::create(['title' => 'Promo Lama Sepuluh Persen', 'code' => 'OLD10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        $oldBooking = $this->booking($customer, $service, 'BK-OLD-PROMO');
        $oldBooking->update(['promo_id' => $promo->id]);
        $before = $oldBooking->refresh()->getRawOriginal();
        $this->actingAs($admin)->get(route('admin.promos.index'))->assertOk()->assertSee('10%')->assertDontSee('Rp 10<', false);
        $this->get(route('admin.promos.edit', $promo))->assertOk()->assertSee('value="percent" selected', false);
        $this->assertSame('percentage', DB::table('promos')->where('id', $promo->id)->value('type'));
        $this->assertSame($before, $oldBooking->fresh()->getRawOriginal());
        $this->post(route('admin.bookings.store'), ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'service_id' => $service->id,
            'promo_id' => $promo->id, 'booking_date' => today()->addDays(2)->toDateString(), 'start_time' => '14:00', 'status' => 'draft', 'payment_status' => 'unpaid'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.bookings.index'));
        $this->assertSame('20000.00', Booking::latest('id')->first()->promo_discount);
        $this->assertSame($before, $oldBooking->fresh()->getRawOriginal());
        $this->assertSame('Rp 25.000', (new Promo(['type' => 'fixed', 'value' => 25000]))->valueLabel());
        $this->assertSame('12,5%', (new Promo(['type' => 'percent', 'value' => 12.5]))->valueLabel());
        $this->post(route('admin.promos.store'), ['title' => 'Persen Tidak Valid', 'type' => 'percent', 'value' => 101])->assertSessionHasErrors('value');
    }

    public function test_sidebar_keeps_parent_active_on_edit_and_create_including_service_group(): void
    {
        $this->actingAs($this->actor());
        $service = Service::create(['name' => 'Laktasi Temu', 'service_group' => 'lactation', 'price' => 200000, 'duration_minutes' => 60, 'is_active' => true]);
        foreach ([route('admin.services.edit', $service) => 'Laktasi & Konsultasi ASI', route('admin.bookings.create') => 'Bookings', route('admin.ai.knowledge.create') => 'Knowledge Base'] as $url => $label) {
            $content = $this->get($url)->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($content);
            $nodes = (new \DOMXPath($dom))->query('//aside//a[@aria-current="page"]');
            $this->assertCount(1, $nodes);
            $this->assertSame($label, trim($nodes->item(0)->textContent));
        }
    }
}
