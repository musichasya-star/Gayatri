<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\KnowledgeBase;
use App\Models\LandingPageSetting;
use App\Models\Service;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\CRM\OperationalOverview;
use App\Support\AdminReviewContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
        Queue::fake();
    }

    private function actor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function customer(string $name = 'Pelanggan nyata'): Customer
    {
        return Customer::create(['name' => $name, 'status' => 'active']);
    }

    private function booking(Customer $customer, string $status, string $code): Booking
    {
        $service = Service::create(['name' => 'Layanan uji', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => true]);

        return Booking::create(['booking_code' => $code, 'customer_id' => $customer->id, 'service_id' => $service->id, 'status' => $status, 'payment_status' => 'unpaid', 'booking_date' => today()->addDay(), 'start_time' => '10:00', 'end_time' => '11:00', 'source' => 'manual', 'created_at' => now()->subDays(10)]);
    }

    public function test_dashboard_backlog_and_destination_include_older_pending_states_without_demo(): void
    {
        $customer = $this->customer();
        foreach (['draft', 'pending', 'pending_confirmation'] as $status) {
            $this->booking($customer, $status, 'BACKLOG-'.$status);
        }
        $this->booking($customer, 'confirmed', 'CONFIRMED-NOT-BACKLOG');
        $this->booking($this->customer('[DEMO] Contoh'), 'pending', 'DEMO-NOT-BACKLOG');
        $this->assertSame(3, app(OperationalOverview::class)->counts()['bookings_pending']);
        $this->actingAs($this->actor())->get(route('admin.dashboard', ['range' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02']))->assertOk()->assertSee('BACKLOG-draft')->assertSee('attention=pending', false);
        $this->get(route('admin.bookings.index', ['attention' => 'pending']))->assertOk()->assertSee('3 booking sesuai filter')->assertSee('BACKLOG-pending_confirmation')->assertDontSee('DEMO-NOT-BACKLOG')->assertDontSee('CONFIRMED-NOT-BACKLOG');
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_due_followup_count_is_not_the_five_row_preview_count(): void
    {
        $customer = $this->customer();
        foreach (range(1, 7) as $number) {
            Followup::create(['customer_id' => $customer->id, 'title' => 'Jatuh tempo '.$number, 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->subDay()]);
        }
        Followup::create(['customer_id' => $customer->id, 'title' => 'Mendatang', 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->addDays(2)]);
        Followup::create(['customer_id' => $this->customer('[DEMO] Followup')->id, 'title' => 'Demo dikecualikan', 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->subDay()]);
        $this->assertSame(7, app(OperationalOverview::class)->counts()['followups_due']);
        $this->actingAs($this->actor())->get(route('admin.followups.index', ['status' => 'open', 'due' => 'today']))->assertOk()->assertSee('Jatuh tempo 7')->assertDontSee('Demo dikecualikan')->assertDontSee('Mendatang');
    }

    public function test_sales_and_therapist_dashboard_does_not_render_financial_workbench(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs($this->actor($role))->get(route('admin.dashboard'))->assertOk()->assertSee('Ruang Kerja')->assertSee('id="notifikasi-operasional"', false)->assertDontSee('Pembayaran booking tercatat')->assertDontSee('Estimasi Revenue');
            $this->get(route('admin.laundry.reports'))->assertForbidden();
            $this->get(route('admin.rentals.reports'))->assertForbidden();
        }
    }

    public function test_invalid_workbench_filters_return_validation_errors(): void
    {
        $this->actingAs($this->actor('owner'));
        foreach (['admin.ai.knowledge.index' => ['domain' => 'bad'], 'admin.users.index' => ['role' => ['bad']], 'admin.customers.index' => ['search' => ['bad']], 'admin.therapist-fees.settings' => ['search' => ['bad']], 'admin.bookings.index' => ['attention' => 'bad'], 'admin.followups.index' => ['due' => 'bad']] as $route => $query) {
            $this->getJson(route($route, $query))->assertUnprocessable();
        }
    }

    public function test_knowledge_demo_filter_separates_training_material(): void
    {
        KnowledgeBase::create(['title' => 'Informasi operasional', 'slug' => 'real-knowledge', 'type' => 'text', 'content' => 'Datang sesuai jadwal layanan.', 'status' => 'active', 'publication_status' => 'draft', 'audience' => 'customer_public', 'is_demo' => false]);
        KnowledgeBase::create(['title' => 'Contoh simulator', 'slug' => 'demo-knowledge', 'type' => 'text', 'content' => 'Contoh untuk latihan lokal.', 'status' => 'active', 'publication_status' => 'draft', 'audience' => 'customer_public', 'is_demo' => true]);
        $this->actingAs($this->actor())->get(route('admin.ai.knowledge.index', ['demo' => 'demo']))->assertOk()->assertSee('Contoh simulator')->assertDontSee('Informasi operasional');
        $this->get(route('admin.ai.knowledge.index', ['demo' => 'real']))->assertOk()->assertSee('Informasi operasional')->assertDontSee('Contoh simulator');
    }

    public function test_review_context_accepts_only_module_filters_and_never_redirect_urls(): void
    {
        $request = Request::create('/', 'GET', ['_list' => 'knowledge', '_filters' => ['domain' => 'spa', 'publication_status' => 'needs_review', 'page' => 2, 'redirect' => 'https://untrusted.test']]);
        $this->assertSame(route('admin.ai.knowledge.index', ['page' => 2, 'domain' => 'spa', 'publication_status' => 'needs_review']), AdminReviewContext::back($request, 'knowledge'));
        $this->assertSame(route('admin.users.index'), AdminReviewContext::back($request, 'users'));
        $this->assertSame(route('admin.ai.knowledge.index'), AdminReviewContext::back(Request::create('/', 'GET', ['_list' => 'knowledge', '_filters' => ['domain' => ['bad']]]), 'knowledge'));
    }

    public function test_user_edit_returns_to_filtered_list_without_broadening_access(): void
    {
        $owner = $this->actor('owner');
        $user = $this->actor('sales');
        $filters = ['search' => $user->name, 'role' => 'sales'];
        $this->actingAs($owner)->put(route('admin.users.update', $user), ['name' => $user->name, 'email' => $user->email, 'phone' => '', 'role' => 'sales', 'status' => 'active', '_list' => 'users', '_filters' => $filters])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index', $filters));
        $this->assertSame('sales', $user->fresh()->role);
    }

    public function test_customer_profile_preserves_list_context_and_hides_booking_write_for_sales(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->actor('sales'))->get(route('admin.customers.show', ['customer' => $customer, '_list' => 'customers', '_filters' => ['search' => 'Pelanggan']]))->assertOk()->assertSee('customers?search=Pelanggan', false)->assertDontSee(route('admin.bookings.create'), false)->assertDontSee('Laundry terbaru');
    }

    public function test_failed_settings_validation_never_flashes_keys_or_webhook_secret(): void
    {
        $this->actingAs($this->actor());
        $this->from(route('admin.settings.index'))->post(route('admin.settings.ai.update'), ['provider' => 'local', 'model' => 'invalid-model', 'temperature' => 0.7, 'max_tokens' => 1000, 'api_key' => 'FAKE-KEY-NOT-A-CREDENTIAL'])->assertSessionHasErrors('model')->assertSessionMissing('_old_input.api_key');
        $this->from(route('admin.settings.index'))->post(route('admin.settings.whatsapp.update'), ['gateway_provider' => 'baileys', 'base_url' => 'bad-url', 'default_session' => 'default', 'timeout' => 30, 'api_key' => 'FAKE-KEY-NOT-A-CREDENTIAL', 'webhook_secret' => 'FAKE-WEBHOOK-NOT-A-CREDENTIAL'])->assertSessionHasErrors('base_url')->assertSessionMissing('_old_input.api_key')->assertSessionMissing('_old_input.webhook_secret');
        Http::assertNothingSent();
    }

    public function test_viewing_gateway_does_not_create_or_start_a_missing_session(): void
    {
        config(['whatsapp.provider' => 'waha', 'waha.base_url' => 'http://waha.test', 'waha.default_session' => 'default']);
        app(AppSettingService::class)->setMany(['whatsapp.provider' => 'waha', 'waha.base_url' => 'http://waha.test', 'waha.default_session' => 'default']);
        Http::fake(['http://waha.test/api/sessions*' => Http::response([], 200)]);
        $this->actingAs($this->actor())->get(route('admin.whatsapp.session'))->assertOk()->assertSee('Sesi belum terdaftar')->assertSee('Mulai sesi');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('whatsapp_sessions', 0);
    }

    public function test_cms_nested_fields_keep_array_structure_and_sibling_settings(): void
    {
        $page = LandingPageSetting::updateOrCreate(['slug' => 'home'], ['site_name' => 'Situs Uji', 'page_settings' => ['booking_page' => ['headline' => 'Judul awal', 'lead' => 'Pengantar tetap'], 'phone' => '08000000000'], 'seo_settings' => [], 'theme_settings' => []]);
        $this->actingAs($this->actor())->get(route('admin.landing.index'))->assertOk()->assertSee('name="page_settings[booking_page][headline]"', false);
        $this->from(route('admin.landing.index'))->post(route('admin.landing.settings.update'), ['site_name' => 'Situs Uji', '_cms_tab' => 'booking-page', 'page_settings' => ['booking_page' => ['headline' => 'Judul baru']]])->assertSessionHasNoErrors()->assertSessionHas('cms_tab', 'booking-page');
        $this->assertSame('Judul baru', $page->fresh()->page_settings['booking_page']['headline']);
        $this->assertSame('Pengantar tetap', $page->fresh()->page_settings['booking_page']['lead']);
        $this->assertSame('08000000000', $page->fresh()->page_settings['phone']);
        $this->from(route('admin.landing.index'))->post(route('admin.landing.settings.update'), ['site_name' => 'Situs Uji', 'page_settings' => ['booking_page' => '{"headline":"Jangan timpa struktur"}']])->assertSessionHasErrors('page_settings.booking_page');
        $this->assertSame('Judul baru', $page->fresh()->page_settings['booking_page']['headline']);
    }
}
