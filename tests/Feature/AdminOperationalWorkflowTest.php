<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\RentalOrder;
use App\Models\RentalPayment;
use App\Models\Service;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\CRM\HomeService;
use App\Support\OperationalGuidance;
use App\Support\OperationalNavigation;
use App\Support\OperationalQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOperationalWorkflowTest extends TestCase
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

    private function thread(): Conversation
    {
        $customer = Customer::create(['name' => 'Pelanggan Inbox Uji', 'whatsapp_number' => '6280000991199', 'status' => 'lead']);

        return Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '6280000991199@c.us', 'status' => 'open', 'ai_enabled' => true, 'unread_count' => 3]);
    }

    public function test_opening_the_list_does_not_choose_or_mark_a_thread_read(): void
    {
        $conversation = $this->thread();
        $this->actingAs($this->actor())->get(route('admin.inbox'))->assertOk()
            ->assertSee('Pilih Conversation')->assertDontSee('name="message"', false);
        $this->assertSame(3, $conversation->fresh()->unread_count);
        $this->actingAs($this->actor())->get(route('admin.inbox', ['conversation' => $conversation->id]))->assertOk()
            ->assertSee('data-inbox-profile', false)->assertSee('data-inbox-refresh-status', false);
        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_sales_has_chat_controls_but_no_booking_write_shortcut(): void
    {
        $conversation = $this->thread();
        $this->actingAs($this->actor('sales'))->get(route('admin.inbox', ['conversation' => $conversation->id]))
            ->assertOk()->assertSee('Balasan WhatsApp')->assertDontSee(route('admin.bookings.create'), false);
        $this->get(route('admin.bookings.index'))->assertForbidden();
    }

    public function test_therapist_cannot_select_a_thread_outside_their_scope(): void
    {
        $conversation = $this->thread();
        $this->actingAs($this->actor('therapist'))->get(route('admin.inbox', ['conversation' => $conversation->id]))->assertNotFound();
        $this->assertSame(3, $conversation->fresh()->unread_count);
    }

    public function test_invalid_operational_filters_return_validation_errors(): void
    {
        $this->actingAs($this->actor());
        foreach (['admin.bookings.index', 'admin.bookings.calendar', 'admin.availability.index'] as $route) {
            $this->getJson(route($route, ['date' => 'not-a-date']))->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->getJson(route('admin.inbox', ['conversation' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('admin.rentals.orders.index', ['search' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('admin.rentals.operations', ['severity' => 'invalid']))->assertUnprocessable();
    }

    public function test_booking_navigation_preserves_only_known_local_list_context(): void
    {
        $filters = ['date' => '2026-11-10', 'service_group' => 'lactation', 'page' => 2];
        $request = Request::create('/', 'GET', ['return_to' => 'bookings', 'return_filters' => $filters]);
        $this->assertSame(route('admin.bookings.index', $filters), OperationalNavigation::back($request));
        $request = Request::create('/', 'GET', ['return_to' => 'booking-calendar', 'return_filters' => ['date' => '2026-11-10']]);
        $this->assertSame(route('admin.bookings.calendar', ['date' => '2026-11-10']), OperationalNavigation::back($request));
        foreach ([['return_to' => 'https://untrusted.test'], ['return_to' => 'bookings', 'return_filters' => ['redirect' => 'https://untrusted.test']], ['return_to' => 'bookings', 'return_filters' => ['date' => 'invalid']]] as $input) {
            $this->assertSame(route('admin.bookings.index'), OperationalNavigation::back(Request::create('/', 'GET', $input)));
        }
    }

    public function test_creating_a_booking_returns_to_filtered_calendar(): void
    {
        $customer = Customer::create(['name' => 'Pemesan Uji', 'whatsapp_number' => '6280000991100', 'status' => 'lead']);
        $service = Service::create(['name' => 'Treatment Uji', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        $filters = ['date' => now()->addDay()->toDateString(), 'service_group' => 'spa'];
        $this->actingAs($this->actor())->post(route('admin.bookings.store'), [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'booking_date' => $filters['date'], 'start_time' => '10:00',
            'status' => 'draft', 'payment_status' => 'unpaid', 'return_to' => 'booking-calendar', 'return_filters' => $filters,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.bookings.calendar', $filters));
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_historical_paid_booking_does_not_show_a_new_outstanding_balance(): void
    {
        $customer = Customer::create(['name' => 'Pelanggan Historis', 'status' => 'active']);
        $service = Service::create(['name' => 'Treatment Historis', 'price' => 999999, 'duration_minutes' => 60, 'is_active' => true]);
        Booking::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => 'HISTORY-PAID', 'booking_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'completed', 'payment_status' => 'paid', 'source' => 'manual']);
        $this->actingAs($this->actor())->get(route('admin.bookings.index', ['search' => 'HISTORY-PAID']))->assertOk()
            ->assertSee('HISTORY-PAID')->assertSee('Status historis')->assertDontSee('999.999');
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_home_service_marks_stale_choices_without_silently_removing_settings(): void
    {
        $ready = Service::create(['name' => 'Online Siap', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        $manual = Service::create(['name' => 'Paket Konfirmasi Admin', 'price' => 300000, 'duration_minutes' => null, 'booking_mode' => 'admin', 'is_active' => true]);
        $settings = array_replace(app(HomeService::class)->settings(), ['service_ids' => [$ready->id, $manual->id]]);
        app(AppSettingService::class)->set('home_service.settings', json_encode($settings));
        $this->actingAs($this->actor())->get(route('admin.home-service.index'))->assertOk()
            ->assertSee('Pilihan lama perlu ditinjau')->assertSee('Paket Konfirmasi Admin')->assertSee('data-choice-search', false);
        $this->assertSame($settings['service_ids'], app(HomeService::class)->settings()['service_ids']);
        $response = $this->put(route('admin.home-service.update'), array_replace($settings, ['service_ids' => [$manual->id]]));
        $response->assertSessionHasErrors('service_ids.0');
        $this->assertSame($settings['service_ids'], app(HomeService::class)->settings()['service_ids']);
        Http::assertNothingSent();
    }

    public function test_home_service_rejects_quote_for_stale_manual_package_before_routing(): void
    {
        $manual = Service::create(['name' => 'Paket Beberapa Kunjungan', 'price' => 300000, 'duration_minutes' => 60, 'catalog_metadata' => ['package_quantity' => 3], 'is_active' => true]);
        $settings = array_replace(app(HomeService::class)->settings(), ['enabled' => true, 'latitude' => -7.8, 'longitude' => 112, 'rate_per_km' => 5000, 'max_distance_km' => 20, 'service_ids' => [$manual->id]]);
        app(AppSettingService::class)->set('home_service.settings', json_encode($settings));
        try {
            app(HomeService::class)->quote(-7.81, 112.01, $manual->id);
            $this->fail('Manual package must not be quoted as a direct booking.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_guidance_distinguishes_weighing_approval_quality_payment_and_handover(): void
    {
        $order = new LaundryOrder(['status' => 'received', 'workflow_status' => 'pending']);
        $this->assertSame('penimbangan', OperationalGuidance::laundry($order)['anchor']);
        $order->actual_total = 100000;
        $order->status = 'awaiting_approval';
        $this->assertSame('persetujuan', OperationalGuidance::laundry($order)['anchor']);
        $order->status = 'approved';
        $order->workflow_status = 'quality_check';
        $this->assertSame('Periksa kualitas', OperationalGuidance::laundry($order)['label']);
        $order->workflow_status = 'ready';
        $order->setRelation('payments', collect());
        $this->assertSame('pembayaran', OperationalGuidance::laundry($order)['anchor']);
        $order->setRelation('payments', collect([new LaundryPayment(['amount' => 100000])]));
        $this->assertSame('pengerjaan', OperationalGuidance::laundry($order)['anchor']);
        $order->workflow_status = 'handed_over';
        $this->assertSame('riwayat', OperationalGuidance::laundry($order)['anchor']);
        $this->assertDatabaseCount('laundry_orders', 0);
    }

    public function test_rental_guidance_keeps_inspection_and_refund_as_separate_actions(): void
    {
        $order = new RentalOrder(['status' => 'reserved']);
        $this->assertSame('Siapkan serah terima', OperationalGuidance::rental($order)['label']);
        $order->status = 'inspection';
        $this->assertSame('Selesaikan pemeriksaan', OperationalGuidance::rental($order)['label']);
        $order->status = 'completed';
        $order->setRelation('items', collect());
        $order->setRelation('payments', collect([new RentalPayment(['kind' => 'payment', 'amount' => 100000])]));
        $this->assertSame('Tinjau saldo refund', OperationalGuidance::rental($order)['label']);
        $this->assertDatabaseCount('rental_orders', 0);
    }

    public function test_queue_back_links_retain_filters_but_drop_unknown_or_malformed_values(): void
    {
        $filters = ['search' => 'DEMO', 'workflow' => 'ready', 'include_demo' => 1, 'page' => 2];
        $this->assertSame($filters, OperationalQueue::laundry(Request::create('/', 'GET', ['queue' => $filters])));
        $this->assertSame([], OperationalQueue::laundry(Request::create('/', 'GET', ['queue' => ['workflow' => ['bad']]])));
        $this->assertSame(['status' => 'rented'], OperationalQueue::rental(Request::create('/', 'GET', ['queue' => ['status' => 'rented', 'redirect' => 'https://untrusted.test']])));
    }

    public function test_saving_an_inbox_note_retains_safe_list_filters(): void
    {
        $thread = $this->thread();
        $filters = ['search' => 'Pelanggan', 'page' => 2, 'need_admin' => 1];
        $this->actingAs($this->actor())->post(route('admin.inbox.note', $thread), ['internal_note' => 'Hubungi kembali besok.', 'inbox_filters' => $filters])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.inbox', ['conversation' => $thread->id, ...$filters]));
        $this->assertSame('Hubungi kembali besok.', $thread->fresh()->internal_note);
    }

    public function test_invalid_home_service_old_input_does_not_break_the_review_form(): void
    {
        $this->actingAs($this->actor())->withSession(['_old_input' => ['selection_submitted' => 1, 'service_ids' => [['invalid']], 'therapist_ids' => 'invalid']])
            ->get(route('admin.home-service.index'))->assertOk()->assertSee('Simpan Home Service');
    }

    public function test_legacy_rental_period_is_readable_without_rewriting_financial_history(): void
    {
        $customer = Customer::create(['name' => 'Penyewa Historis', 'status' => 'active']);
        $order = RentalOrder::create(['code' => 'LEGACY-WEEKLY', 'quote_id' => (string) Str::uuid(), 'request_session_hash' => str_repeat('a', 64), 'pricing_snapshot' => [], 'customer_id' => $customer->id, 'status' => 'completed', 'period' => 'weekly', 'period_count' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-07', 'fulfilment' => 'self', 'rental_subtotal' => 100000, 'deposit_total' => 50000, 'delivery_fee' => 0, 'collection_fee' => 0]);
        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($this->actor())->get(route('admin.rentals.orders.show', $order))->assertOk()
            ->assertSee('Periode tersimpan: weekly')->assertSee('id="unit-pengembalian"', false)->assertSee('id="pembayaran"', false);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('rental_payments', 0);
    }
}
