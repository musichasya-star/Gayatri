<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryTariff;
use App\Models\Message;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AI\AiKnowledgeReadiness;
use App\Services\AI\AiService;
use App\Services\CRM\BookingFinance;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\WhatsApp\WhatsAppSenderIdentity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiCustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'Asia/Jakarta'));
        Queue::fake();
        Storage::fake('local');
        config(['app.url' => 'http://localhost', 'whatsapp.provider' => 'baileys', 'baileys.default_session' => 'qa-e', 'baileys.webhook_secret' => 'isolated-qa-e']);
        $this->admin = User::factory()->create(['role' => 'owner', 'status' => 'active']);
    }

    private function lookup(Customer $customer, string $code, ?string $phone = null): array
    {
        $session = WhatsAppSession::firstOrCreate(['session_name' => 'qa-e'], ['status' => 'WORKING']);
        $conversation = Conversation::firstOrCreate(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id], [
            'wa_chat_id' => $customer->whatsapp_number.'@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
        $text = 'cek status '.$code.' '.($phone ?? $customer->whatsapp_number);
        $event = ['session' => 'qa-e', '_gayatri_gateway' => ['provider' => 'baileys', 'session' => 'qa-e', 'accepted_at' => now()->timestamp],
            'payload' => ['id' => (string) Str::uuid(), 'from' => $customer->whatsapp_number.'@c.us', 'body' => $text, 'timestamp' => now()->timestamp]];
        $source = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'incoming',
            'sender_type' => 'customer', 'message_type' => 'text', 'content' => $text, 'wa_message_id' => $event['payload']['id'], 'sent_at' => now(), 'payload' => $event['payload']]);
        app(WhatsAppSenderIdentity::class)->observe($event, $session, $source);

        return app(AiService::class)->generateReply($conversation->fresh(), $source->fresh());
    }

    public static function bookingDomains(): array
    {
        return [['spa', 'Pijat Bayi'], ['lactation', 'Konsultasi ASI']];
    }

    #[DataProvider('bookingDomains')]
    public function test_booking_journey_from_chat_to_web_manual_payment_and_owner_lookup(string $domain, string $name): void
    {
        $service = Service::create(['name' => $name, 'service_group' => $domain, 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        $slot = AvailabilitySlot::create(['service_id' => $service->id, 'slot_date' => today()->addDay(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        $chat = app(AiService::class)->simulate('mau booking '.$name);
        $this->assertSame($domain, $chat['routing']['domain']);
        preg_match('~http://localhost/booking[^\s]*~', $chat['reply'], $link);
        $this->get($link[0])->assertOk()->assertSee($name);
        $this->assertDatabaseCount('bookings', 0);
        $this->post(route('landing.booking'), ['name' => 'Pemesan Pengujian', 'whatsapp_number' => '6280000000801', 'service_group' => $domain,
            'service_id' => $service->id, 'availability_slot_id' => $slot->id, 'booking_date' => $slot->slot_date->toDateString(), 'start_time' => '10:00', 'branch_id' => null])->assertSessionHasNoErrors()->assertRedirect();
        $booking = Booking::sole();
        $this->assertSame('pending_confirmation', $booking->status);
        $this->assertSame('lookup_verified', $this->lookup($booking->customer, $booking->booking_code)['routing']['reason_code']);
        $this->assertDatabaseCount('booking_payments', 0);
        $this->actingAs($this->admin)->post(route('admin.bookings.verify-contact', $booking), ['reference' => 'Kontak pengujian dikonfirmasi.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $booking->refresh();
        $this->post(route('admin.bookings.finance.record', $booking), ['revision' => $booking->finance_revision, 'fingerprint' => app(BookingFinance::class)->fingerprint($booking),
            'entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 40000, 'method' => 'cash', 'paid_at' => now()->format('Y-m-d\TH:i'), 'payer_name' => 'Pembayar Pengujian', 'confirmed' => 1])->assertSessionHasNoErrors();
        $paid = $this->lookup($booking->customer, $booking->booking_code);
        $this->assertStringContainsString('Sebagian / DP terverifikasi', $paid['reply']);
        $this->assertStringContainsString('Rp 60.000', $paid['reply']);
        $payment = $booking->payments()->sole();
        $this->get(route('admin.bookings.finance.receipt', [$booking, $payment]))->assertOk();
        $outsider = Customer::create(['name' => 'Penguji lain', 'whatsapp_number' => '6280000000802', 'status' => 'active']);
        $this->assertSame('lookup_denied', $this->lookup($outsider, $booking->booking_code, $booking->customer->whatsapp_number)['routing']['reason_code']);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_payments', 1);
        Http::assertNothingSent();
    }

    public function test_rental_journey_preserves_pending_proof_until_admin_verifies_it(): void
    {
        $product = RentalProduct::create(['sku' => 'UAT-E', 'name' => 'Stroller Uji', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'is_active' => true]);
        RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'UAT-UNIT-E', 'status' => 'ready']);
        $reply = app(AiService::class)->simulate('mau sewa stroller');
        preg_match('~http://localhost/rental[^\s]*~', $reply['reply'], $link);
        $this->get($link[0])->assertOk()->assertSee('Stroller Uji');
        $this->assertDatabaseCount('rental_orders', 0);
        $this->post(route('rental.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(2)->toDateString(),
            'period' => 'day', 'fulfilment' => 'self', 'name' => 'Penyewa Pengujian', 'whatsapp_number' => '6280000000803', 'terms_accepted' => 1];
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        $this->post(route('rental.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $order = RentalOrder::sole();
        $this->assertSame('lookup_verified', $this->lookup($order->customer, $order->code)['routing']['reason_code']);
        $this->actingAs($this->admin)->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();
        $this->app['auth']->guard('web')->logout();
        $this->post(route('rental.portal.proof', $order->code), ['entry_key' => (string) Str::uuid(), 'kind' => 'settlement', 'amount' => $order->fresh()->balance(),
            'payer_name' => 'Penyewa Pengujian', 'payment_date' => today()->toDateString(), 'proof' => UploadedFile::fake()->image('bukti-uji.jpg')])->assertSessionHasNoErrors();
        $pending = $this->lookup($order->customer, $order->code);
        $this->assertStringContainsString('menunggu verifikasi', $pending['reply']);
        $this->assertDatabaseCount('rental_payments', 0);
        $proof = $order->paymentProofs()->sole();
        $this->actingAs($this->admin)->post(route('admin.rentals.proofs.review', [$order, $proof]), ['action' => 'approve', 'review_notes' => 'Transfer pengujian dicocokkan.'])->assertSessionHasNoErrors();
        $paid = $this->lookup($order->customer, $order->code);
        $this->assertSame('lookup_verified', $paid['routing']['reason_code']);
        $this->assertStringNotContainsString('Bukti pembayaran: menunggu', $paid['reply']);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertStringNotContainsString('portal_token', $paid['reply']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_laundry_journey_from_estimate_weighing_consent_proof_and_receipt_matches_chat_status(): void
    {
        app(LaundrySettings::class)->save(['accepting_orders' => true, 'outlet_name' => 'Outlet Pengujian', 'address' => 'Alamat outlet pengujian', 'whatsapp_number' => '6280000000000', 'allow_collection' => true], $this->admin, Request::create('/'));
        $public = app(LaundryPublicSettings::class);
        $public->save(array_replace($public->get(), ['enabled' => true]), $this->admin, Request::create('/'));
        $tariff = LaundryTariff::create(['code' => 'UAT-E-KG', 'name' => 'Cuci Bayi', 'audience' => 'baby', 'unit' => 'kg', 'price' => 10000,
            'minimum_milli' => 1000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Pelanggan Laundry Uji', 'whatsapp_number' => '6280000000804', 'status' => 'active']);
        $chat = app(AiService::class)->simulate('mau laundry baju bayi');
        $this->assertStringContainsString('http://localhost/laundry', $chat['reply']);
        $key = $this->get(route('laundry.index'))->assertOk()->viewData('entryKey');
        $preview = $this->post(route('laundry.preview'), ['entry_key' => $key, 'name' => $customer->name, 'whatsapp_number' => $customer->whatsapp_number,
            'fulfilment' => 'dropoff', 'items' => [['laundry_tariff_id' => $tariff->id, 'quantity' => '2']]])->assertOk()->viewData('preview');
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        $entry = LaundryPublicRequest::sole();
        $this->assertStringContainsString('Belum menjadi order', $this->lookup($customer, $entry->public_id)['reply']);
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.verify', $entry), ['revision' => $entry->revision, 'decision' => 'verify', 'reference' => 'Kontak pengujian diperiksa.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.requests.intake', $entry->fresh()), ['confirmed' => 1])->assertRedirect();
        $draft = session('laundry.intake_draft');
        $draft['items'][0]['received_pieces'] = 2;
        $draft['items'][0]['condition_notes'] = 'Dua pakaian pengujian diperiksa.';
        $preview = $this->post(route('admin.laundry.orders.preview'), $draft)->assertOk()->viewData('preview');
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        $order = LaundryOrder::sole();
        $this->post(route('admin.laundry.orders.weigh', $order), ['revision' => $order->revision, 'items' => [['id' => $order->items()->sole()->id, 'actual_pieces' => 2, 'quantity' => '2.5']]])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $order->refresh();
        $this->post(route('laundry.portal.approve', $entry->public_id), ['revision' => $order->revision, 'fingerprint' => $order->pricingFingerprint(), 'approved_name' => $customer->name, 'confirmed' => 1])->assertSessionHasNoErrors();
        $order->refresh();
        $this->post(route('laundry.portal.proof', $entry->public_id), ['entry_key' => (string) Str::uuid(), 'revision' => $order->revision, 'fingerprint' => $order->pricingFingerprint(),
            'amount' => 10000, 'paid_at' => now()->format('Y-m-d\TH:i'), 'payer_name' => $customer->name, 'reference' => 'UAT-E-TRANSFER', 'confirmed' => 1, 'file' => UploadedFile::fake()->image('bukti-uji.jpg')])->assertSessionHasNoErrors();
        $pending = $this->lookup($customer, $order->code);
        $this->assertStringContainsString('menunggu verifikasi', $pending['reply']);
        $this->assertDatabaseCount('laundry_payments', 0);
        $proof = $order->paymentProofs()->sole();
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.proofs.review', $proof), ['revision' => $proof->revision, 'decision' => 'approve', 'notes' => 'Mutasi pengujian dicocokkan.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $paid = $this->lookup($customer, $order->code);
        $this->assertStringContainsString('Sebagian / DP terverifikasi', $paid['reply']);
        $this->assertStringContainsString('Rp 15.000', $paid['reply']);
        $this->get(route('laundry.portal.document', [$entry->public_id, $order->documents()->where('type', 'receipt')->sole()->id]))->assertOk();
        $this->assertDatabaseCount('laundry_payments', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_knowledge_review_is_idempotent_preserves_edits_and_never_auto_publishes(): void
    {
        $this->artisan('ai:prepare-knowledge-review')->assertSuccessful();
        $this->assertDatabaseCount('knowledge_bases', 12);
        DB::table('knowledge_bases')->where('slug', 'review-ai-e-rental-perpanjangan')->update(['content' => 'Catatan pemeriksaan admin.']);
        $this->artisan('ai:prepare-knowledge-review')->assertSuccessful();
        $this->assertDatabaseHas('knowledge_bases', ['slug' => 'review-ai-e-rental-perpanjangan', 'content' => 'Catatan pemeriksaan admin.', 'publication_status' => 'draft']);
        $this->assertSame(0, DB::table('knowledge_bases')->where('publication_status', 'published')->count());
        $report = app(AiKnowledgeReadiness::class)->report();
        $this->assertFalse($report['ready_for_live_faq']);
        $this->assertSame(4, $report['domains']['rental']['review_required']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
