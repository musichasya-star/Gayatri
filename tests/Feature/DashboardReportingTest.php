<?php

namespace Tests\Feature;

use App\Models\AiAutomationApproval;
use App\Models\AiExtractedData;
use App\Models\AiLog;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\Message;
use App\Models\Service;
use App\Models\User;
use App\Support\BookingStatus;
use App\Support\CampaignStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_real_kpis_with_date_filter(): void
    {
        [$admin] = $this->seedReportData();

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => 'month']))
            ->assertOk()
            ->assertSee('Ringkasan Bisnis Bulan Ini')
            ->assertSee('Estimasi Revenue')
            ->assertSee('Rp 225.000')
            ->assertSee('Campaign Aktif')
            ->assertSee('AI Answered');
    }

    public function test_dashboard_shows_incoming_booking_notification(): void
    {
        [$admin,, $customer, $service, $conversation] = $this->seedReportData();
        Booking::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'conversation_id' => $conversation->id,
            'booking_code' => 'BK-INCOMING-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'ai_flow',
        ]);
        $rescheduleExtracted = AiExtractedData::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'intent' => 'booking_reschedule_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        $cancelExtracted = AiExtractedData::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        AiAutomationApproval::create([
            'ai_extracted_data_id' => $rescheduleExtracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
            'mode' => 'approval_required',
            'status' => 'pending',
            'proposed_data' => [
                'booking' => [
                    'booking_code' => 'BK-REPORT-001',
                    'current_booking_date' => now()->toDateString(),
                    'current_start_time' => '10:00:00',
                    'booking_date' => now()->addDay()->toDateString(),
                    'start_time' => '14:00:00',
                ],
            ],
        ]);
        AiAutomationApproval::create([
            'ai_extracted_data_id' => $cancelExtracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'cancel_booking',
            'mode' => 'approval_required',
            'status' => 'pending',
            'proposed_data' => [
                'booking' => [
                    'booking_code' => 'BK-CANCEL-001',
                    'booking_date' => now()->addDay()->toDateString(),
                    'start_time' => '15:00:00',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Notifikasi Operasional')
            ->assertSee('request customer perlu dicek')
            ->assertSee('BK-INCOMING-001')
            ->assertSee('Perubahan jadwal')
            ->assertSee('Request pembatalan')
            ->assertSee('BK-CANCEL-001')
            ->assertSee('15:00')
            ->assertSee('Notifikasi operasional');
    }

    public function test_reports_index_and_detail_can_be_viewed(): void
    {
        [$admin] = $this->seedReportData();
        $params = ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString()];

        $this->actingAs($admin)
            ->get(route('admin.reports.index', $params))
            ->assertOk()
            ->assertSee('Reporting &amp; Analytics', false)
            ->assertSee('Revenue');

        $this->actingAs($admin)
            ->get(route('admin.reports.show', ['type' => 'bookings'] + $params))
            ->assertOk()
            ->assertSee('Booking Report')
            ->assertSee('BK-REPORT-001');
    }

    public function test_report_can_be_exported_as_csv(): void
    {
        [$admin] = $this->seedReportData();
        $params = ['type' => 'revenue', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString()];

        $response = $this->actingAs($admin)->get(route('admin.reports.export', $params));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('"Net"', $response->getContent());
        $this->assertStringContainsString('"225000"', $response->getContent());
    }

    public function test_sales_can_access_campaign_and_followup_reports(): void
    {
        [, $sales] = $this->seedReportData();

        $this->actingAs($sales)
            ->get(route('admin.reports.show', ['type' => 'campaigns']))
            ->assertOk()
            ->assertSee('Campaign Report');

        $this->actingAs($sales)
            ->get(route('admin.reports.show', ['type' => 'followups']))
            ->assertOk()
            ->assertSee('Follow-Up Report');

        $this->actingAs($sales)
            ->get(route('admin.reports.show', ['type' => 'revenue']))
            ->assertForbidden();

        $this->actingAs($sales)
            ->get(route('admin.reports.export', ['type' => 'customers']))
            ->assertForbidden();
    }

    private function seedReportData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Gayatri Jakarta', 'code' => 'GJKT', 'status' => 'active']);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Report',
            'phone' => '628166600001',
            'whatsapp_number' => '628166600001',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $service = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa Report', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'unread_count' => 2,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-REPORT-001',
            'booking_date' => now()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::COMPLETED,
            'payment_status' => PaymentStatus::PAID,
            'source' => 'manual',
            'promo_discount' => 25000,
        ]);
        Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'Halo']);
        AiLog::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'prompt' => 'Halo', 'response' => 'Halo Bunda', 'confidence' => 0.9, 'status' => 'success']);
        Campaign::create(['created_by' => $sales->id, 'name' => 'Campaign Report', 'message_template' => 'Halo', 'status' => CampaignStatus::RUNNING, 'recipient_count' => 1, 'sent_count' => 1]);
        Followup::create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'title' => 'Follow-up Report', 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->addDay()]);

        return [$admin, $sales, $customer, $service, $conversation];
    }
}
