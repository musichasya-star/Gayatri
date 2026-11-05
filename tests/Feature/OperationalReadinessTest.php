<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Branch;
use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\CRM\OperationalReadiness;
use App\Services\WhatsApp\GatewayHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->travelTo(now()->setTime(12, 0));
        $this->mock(GatewayHealth::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['reachable' => true, 'checked_at' => now()->toDateTimeString(), 'provider' => 'Baileys', 'sessions' => [['name' => 'default', 'status' => 'WORKING']], 'webhooks_pending' => 0]));
        config(['whatsapp.provider' => 'baileys', 'baileys.default_session' => 'default']);
    }

    public function test_readiness_requires_privileged_active_login_and_sidebar_agrees(): void
    {
        $this->get(route('admin.readiness.index'))->assertRedirect(route('login'));
        $this->get(route('admin.readiness.export'))->assertRedirect(route('login'));
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.readiness.index'))->assertForbidden();
            $this->get(route('admin.readiness.export'))->assertForbidden();
            $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Kesiapan Operasional');
        }
        foreach (['owner', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.readiness.index'))->assertOk()->assertSee('Kesiapan Operasional')->assertSee('aria-current="page"', false)->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_checks_do_not_mutate_business_data_or_expose_secrets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-4.1-mini', 'ai.api_key' => 'private-secret-readiness-test', 'baileys.api_key' => 'private-gateway-key']);
        $tables = ['customers', 'bookings', 'booking_payments', 'rental_orders', 'rental_payments', 'laundry_orders', 'laundry_payments', 'app_settings', 'knowledge_bases', 'jobs'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $response = $this->get(route('admin.readiness.index'))->assertOk()->assertDontSee('private-secret-readiness-test')->assertDontSee('private-gateway-key');
        $response->assertSee('gpt-4.1-mini')->assertSee('Belum siap')->assertSee('Belum ada hasil pengujian');
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_only_future_public_available_slots_count(): void
    {
        $real = Branch::create(['code' => 'KDR', 'name' => 'Outlet Kediri', 'status' => 'active']);
        $demo = Branch::create(['code' => 'DEMO-TEST', 'name' => '[DEMO] Outlet', 'status' => 'active']);
        $service = Service::create(['name' => 'Baby Massage Uji', 'service_group' => 'spa', 'booking_mode' => 'online', 'is_active' => true, 'price' => 40000, 'duration_minutes' => 30]);
        $make = fn ($data) => AvailabilitySlot::create($data + ['branch_id' => $real->id, 'service_id' => $service->id, 'slot_date' => today()->addDay(), 'start_time' => '09:00', 'end_time' => '09:30', 'status' => 'available', 'capacity' => 1, 'booked_count' => 0]);
        $make(['slot_date' => today(), 'start_time' => '08:00']);
        $make(['status' => 'blocked']);
        $make(['booked_count' => 1]);
        $make(['branch_id' => $demo->id]);
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame('blocked', $report['checks']['slots_spa']['status']);
        $make([]);
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame('ready', $report['checks']['slots_spa']['status']);
        $this->assertStringStartsWith('1 slot', $report['checks']['slots_spa']['detail']);
        $this->assertSame('blocked', $report['checks']['slots_lactation']['status']);
    }

    public function test_connected_gateway_and_configured_key_do_not_imply_faq_or_laundry_ready(): void
    {
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-4.1-mini', 'ai.api_key' => 'test']);
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame('ready', $report['checks']['whatsapp']['status']);
        $this->assertSame('attention', $report['checks']['knowledge']['status']);
        $this->assertSame('blocked', $report['checks']['laundry']['status']);
        $this->assertSame([], $report['evidence']);
        $this->assertSame('attention', $report['checks']['queue']['status']);
    }

    public function test_evidence_is_private_whitelisted_and_invalidated_after_key_change_or_expiry(): void
    {
        config(['ai.provider' => 'openai', 'ai.model' => 'gpt-4.1-mini', 'ai.api_key' => 'one']);
        $service = app(OperationalReadiness::class);
        Storage::disk('local')->put(OperationalReadiness::EVIDENCE_PATH, json_encode(['ai_connection' => ['passed' => true, 'checked_at' => now()->toIso8601String(), 'fingerprint' => $service->aiFingerprint(), 'secret' => 'hidden'], 'transactions' => ['passed' => true, 'checked_at' => now()->subDays(2)->toIso8601String()], 'arbitrary' => ['secret' => 'hidden']]));
        $rows = $service->evidence();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['current']);
        $this->assertFalse($rows[1]['current']);
        $this->assertStringNotContainsString('hidden', json_encode($rows));
        config(['ai.api_key' => 'two']);
        $this->assertFalse($service->evidence()[0]['current']);
        Storage::disk('local')->put(OperationalReadiness::EVIDENCE_PATH, '{invalid');
        $this->assertSame([], $service->evidence());
    }

    public function test_missing_data_export_supports_admin_review_and_neutralizes_spreadsheet_formulas(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        Service::create(['name' => '=HYPERLINK("https://example.invalid")', 'catalog_code' => 'MISSING', 'service_group' => 'spa', 'booking_mode' => 'admin', 'is_active' => true, 'price' => 40000, 'duration_minutes' => null]);
        RentalProduct::create(['sku' => 'UNIT-TEST', 'name' => 'Stroller Uji', 'category' => 'Stroller', 'is_active' => true, 'daily_rate' => 10000, 'weekly_rate' => 50000, 'deposit' => null, 'turnaround_days' => 1]);
        $this->assertStringContainsString('1 tanpa unit', app(OperationalReadiness::class)->report()['checks']['rental']['detail']);
        LaundryTariff::create(['code' => 'TARIF-UJI', 'name' => 'Laundry Uji', 'audience' => 'baby', 'unit' => 'kg', 'price' => 10000, 'is_active' => true, 'minimum_milli' => 0, 'increment_milli' => 0, 'duration_hours' => 0]);
        $content = $this->get(route('admin.readiness.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $content);
        foreach (['MISSING', 'durasi', 'UNIT-TEST', 'Deposit', 'TARIF-UJI', 'minimum'] as $value) {
            $this->assertStringContainsString(mb_strtolower($value), mb_strtolower($content));
        }
        Queue::assertNothingPushed();
    }
}
