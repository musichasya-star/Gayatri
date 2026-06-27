<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignRecipientJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Models\AiPersona;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Promo;
use App\Models\Service;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\CRM\CampaignService;
use App\Services\WhatsApp\WahaService;
use App\Support\BookingStatus;
use App\Support\CampaignStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PromoCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_and_update_promo(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);

        $this->actingAs($sales)
            ->post(route('admin.promos.store'), [
                'title' => 'Promo Baby Spa',
                'code' => 'BABY10',
                'type' => 'percent',
                'value' => 10,
                'quota' => 5,
                'is_active' => 1,
                'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'end_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.promos.index'));

        $promo = Promo::firstOrFail();

        $this->actingAs($sales)
            ->put(route('admin.promos.update', $promo), [
                'title' => 'Promo Baby Spa Updated',
                'code' => 'BABY11',
                'type' => 'fixed',
                'value' => 25000,
                'quota' => 10,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.promos.index'));

        $this->assertDatabaseHas('promos', [
            'id' => $promo->id,
            'title' => 'Promo Baby Spa Updated',
            'code' => 'BABY11',
            'quota' => 10,
        ]);
    }

    public function test_active_promo_can_be_applied_to_booking_and_reduces_quota(): void
    {
        [$admin, $customer, $branch, $service] = $this->seedCampaignData();
        $promo = Promo::create([
            'title' => 'Diskon Booking',
            'code' => 'BOOK25',
            'type' => 'fixed',
            'value' => 25000,
            'quota' => 2,
            'is_active' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addWeek(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'promo_id' => $promo->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $booking = Booking::firstOrFail();
        $promo->refresh();

        $this->assertSame($promo->id, $booking->promo_id);
        $this->assertSame('25000.00', $booking->promo_discount);
        $this->assertSame(1, $promo->used_count);
    }

    public function test_campaign_draft_can_be_scheduled_and_locks_recipients(): void
    {
        [$sales] = $this->seedCampaignData();
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        Customer::create(['name' => 'Bunda Inactive', 'whatsapp_number' => '628111000002', 'status' => CustomerStatus::ACTIVE, 'last_booking_at' => now()->subDays(45), 'tags' => ['baby_spa']]);
        Customer::create(['name' => 'Bunda Fresh', 'whatsapp_number' => '628111000003', 'status' => CustomerStatus::ACTIVE, 'last_booking_at' => now()->subDays(3), 'tags' => ['baby_spa']]);

        $this->actingAs($sales)
            ->post(route('admin.campaigns.store'), [
                'name' => 'Campaign Repeat Baby Spa',
                'message_template' => 'Halo Bunda {name}, ada promo {promo_title}.',
                'filter_status' => CustomerStatus::ACTIVE,
                'filter_tag' => 'baby_spa',
                'filter_inactive_days' => 30,
            ])
            ->assertRedirect(route('admin.campaigns.index'));

        $campaign = Campaign::firstOrFail();

        $this->actingAs($sales)
            ->post(route('admin.campaigns.schedule', $campaign), ['scheduled_at' => now()->format('Y-m-d H:i:s')])
            ->assertSessionHasErrors('campaign');

        $this->actingAs($manager)
            ->post(route('admin.campaigns.approve', $campaign))
            ->assertSessionHasNoErrors();

        $this->actingAs($sales)
            ->post(route('admin.campaigns.schedule', $campaign), ['scheduled_at' => now()->format('Y-m-d H:i:s')])
            ->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::SCHEDULED, $campaign->status);
        $this->assertSame(2, $campaign->recipient_count);
        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    public function test_campaign_segment_can_use_service_interest(): void
    {
        [$sales, $customer, $branch, $service] = $this->seedCampaignData();
        Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'created_by' => $sales->id,
            'booking_code' => 'BK-SVC-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::COMPLETED,
            'payment_status' => PaymentStatus::PAID,
            'source' => 'manual',
        ]);
        Customer::create(['name' => 'Bunda Massage', 'whatsapp_number' => '628111000004', 'status' => CustomerStatus::ACTIVE, 'tags' => ['mom_massage']]);

        $this->actingAs($sales)
            ->post(route('admin.campaigns.store'), [
                'name' => 'Campaign Interest Baby Spa',
                'message_template' => 'Halo Bunda {name}, waktunya Baby Spa lagi.',
                'filter_service_interest' => 'Baby Spa',
            ])
            ->assertRedirect(route('admin.campaigns.index'));

        $campaign = Campaign::firstOrFail();
        $this->assertCount(1, app(CampaignService::class)->previewTargets($campaign->audience_filters));
    }

    public function test_campaign_recipient_job_sends_whatsapp_and_records_result(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-campaign-001'], 200)]);

        [$admin, $customer] = $this->seedCampaignData();
        $promo = Promo::create(['title' => 'Promo Repeat', 'code' => 'REPEAT10', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $campaign = Campaign::create([
            'promo_id' => $promo->id,
            'created_by' => $admin->id,
            'name' => 'Campaign Send',
            'message_template' => 'Halo Bunda {name}, kode promo {promo_code}.',
            'status' => CampaignStatus::RUNNING,
            'recipient_count' => 1,
        ]);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'pending']);

        (new ProcessCampaignRecipientJob($recipient->id))->handle(app(WahaService::class), app(CampaignService::class));

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame('sent', $recipient->status);
        $this->assertSame('wamid-campaign-001', $recipient->wa_message_id);
        $this->assertSame(CampaignStatus::COMPLETED, $campaign->status);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid-campaign-001', 'sender_type' => 'system']);
    }

    public function test_due_campaign_dispatches_recipients_through_queue(): void
    {
        Queue::fake();
        [$admin, $customer] = $this->seedCampaignData();
        $campaign = Campaign::create([
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'name' => 'Campaign Queue',
            'message_template' => 'Halo Bunda {name}.',
            'status' => CampaignStatus::SCHEDULED,
            'scheduled_at' => now()->subMinute(),
            'recipient_count' => 2,
        ]);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'pending']);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => Customer::create(['name' => 'Bunda Queue', 'whatsapp_number' => '628111000009', 'status' => CustomerStatus::ACTIVE])->id, 'status' => 'pending']);

        $count = app(CampaignService::class)->dispatchDue();

        $this->assertSame(2, $count);
        Queue::assertPushed(ProcessCampaignRecipientJob::class, 2);
    }

    public function test_campaign_tracks_reply_and_booking_conversion(): void
    {
        [$admin, $customer, $branch, $service] = $this->seedCampaignData();
        $campaign = Campaign::create([
            'created_by' => $admin->id,
            'name' => 'Campaign Tracking',
            'message_template' => 'Halo Bunda {name}.',
            'status' => CampaignStatus::COMPLETED,
            'recipient_count' => 1,
            'sent_count' => 1,
        ]);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'sent', 'sent_at' => now()->subHour()]);

        (new ProcessIncomingWhatsAppMessageJob([
            'session' => 'default',
            'payload' => ['from' => $customer->whatsapp_number.'@c.us', 'id' => 'wamid-reply-001', 'body' => 'Saya mau booking', 'timestamp' => time()],
        ]))->handle();

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $recipient->refresh();
        $this->assertNotNull($recipient->replied_at);
        $this->assertNotNull($recipient->booked_at);
        $this->assertNotNull($recipient->booking_id);
    }

    public function test_ai_only_mentions_active_usable_promos(): void
    {
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'ramah', 'is_active' => true]);
        Promo::create(['title' => 'Promo Aktif', 'code' => 'ACTIVE10', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        Promo::create(['title' => 'Promo Expired', 'code' => 'OLD10', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'start_at' => now()->subDays(5), 'end_at' => now()->subDay()]);

        $result = app(AiService::class)->simulate('Ada promo atau diskon apa?');

        $this->assertStringContainsString('Promo Aktif', $result['reply']);
        $this->assertStringNotContainsString('Promo Expired', $result['reply']);
    }

    public function test_ai_promo_reply_uses_database_before_external_provider(): void
    {
        config()->set('ai.provider', 'openrouter');
        config()->set('ai.api_key', 'test-key');
        config()->set('ai.model', 'test-model');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'ramah', 'is_active' => true]);
        Promo::create(['title' => 'Voucher Juni', 'code' => 'JUNI20', 'type' => 'percent', 'value' => 20, 'is_active' => true, 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Informasi voucer promo belum tersedia.']]],
            ], 200),
        ]);

        $result = app(AiService::class)->simulate('apakah ada voucer promo ?');

        $this->assertStringContainsString('Voucher Juni', $result['reply']);
        $this->assertStringContainsString('JUNI20', $result['reply']);
        $this->assertStringNotContainsString('belum tersedia', $result['reply']);
    }

    private function seedCampaignData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Gayatri Jakarta', 'code' => 'GJKT', 'status' => 'active']);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Campaign',
            'phone' => '628111000001',
            'whatsapp_number' => '628111000001',
            'status' => CustomerStatus::ACTIVE,
            'last_booking_at' => now()->subDays(40),
            'tags' => ['baby_spa'],
        ]);
        $service = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa Premium', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);

        return [$admin, $customer, $branch, $service];
    }
}
