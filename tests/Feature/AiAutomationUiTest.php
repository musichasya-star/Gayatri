<?php

namespace Tests\Feature;

use App\Models\AiAutomationApproval;
use App\Models\AiAutomationLog;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\CustomerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAutomationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_data_automation_pages_and_create_rule(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.ai.data-automation.index'))
            ->assertOk()
            ->assertSee('AI Data Automation');

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.rules.store'), [
                'name' => 'Booking Draft Approval',
                'trigger_event' => 'message_extracted',
                'target_entity' => 'booking',
                'action' => 'create_booking_draft',
                'mode' => 'need_confirmation',
                'confidence_threshold' => '0.85',
                'required_fields' => 'service_id, booking_date, start_time',
                'forbidden_intents' => 'medical, refund, complaint',
                'condition_intents' => 'booking_request',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.ai.data-automation.rules.index'));

        $this->assertDatabaseHas('ai_automation_rules', [
            'name' => 'Booking Draft Approval',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_extracted_data_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [$customer, $conversation, $message] = $this->seedMessage();
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_request',
            'confidence_score' => 0.91,
            'extracted_customer_data' => ['name' => 'Rina'],
            'extracted_booking_data' => ['service_name' => 'Baby Spa'],
            'missing_fields' => [],
            'raw_ai_response' => ['intent' => 'booking_request'],
            'status' => 'pending_approval',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ai.data-automation.extracted.show', $extracted))
            ->assertOk()
            ->assertSee('Detail Extracted Data')
            ->assertSee('booking_request')
            ->assertSee('Rina');
    }

    public function test_admin_can_approve_booking_approval_into_booking_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [$customer, $conversation, $message] = $this->seedMessage();
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_request',
            'confidence_score' => 0.91,
            'extracted_booking_data' => ['service_id' => $service->id, 'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00'],
            'missing_fields' => [],
            'raw_ai_response' => ['intent' => 'booking_request'],
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'proposed_data' => [
                'booking' => ['service_id' => $service->id, 'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00'],
            ],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.approve', $approval))
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $this->assertDatabaseHas('ai_automation_approvals', [
            'id' => $approval->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => 'draft',
            'source' => 'ai_approval',
        ]);
        $this->assertDatabaseHas('ai_automation_logs', [
            'ai_automation_approval_id' => $approval->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_reject_approval_with_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [$customer, $conversation, $message] = $this->seedMessage('628123450099');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_request',
            'confidence_score' => 0.8,
            'raw_ai_response' => ['intent' => 'booking_request'],
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => []],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.reject', $approval), [
                'rejection_reason' => 'Data booking belum lengkap.',
            ])
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $this->assertDatabaseHas('ai_automation_approvals', [
            'id' => $approval->id,
            'status' => 'rejected',
            'rejected_by' => $admin->id,
            'rejection_reason' => 'Data booking belum lengkap.',
        ]);
        $this->assertDatabaseHas('ai_automation_logs', [
            'ai_automation_approval_id' => $approval->id,
            'status' => 'rejected',
            'error_message' => 'Data booking belum lengkap.',
        ]);
    }

    public function test_admin_can_view_automation_log_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $log = AiAutomationLog::create([
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'status' => 'pending_approval',
            'input_payload' => ['intent' => 'booking_request'],
            'output_payload' => ['approval_id' => 1],
            'created_by_ai' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ai.data-automation.logs.show', $log))
            ->assertOk()
            ->assertSee('Detail Automation Log')
            ->assertSee('create_booking_draft');
    }

    public function test_admin_can_run_test_automation_without_persisting_extracted_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AiAutomationRule::create([
            'name' => 'Booking Draft Approval',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'confidence_threshold' => 0.85,
            'required_fields' => ['service_id', 'booking_date', 'start_time'],
            'forbidden_intents' => ['medical'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.test.run'), [
                'message' => 'Saya mau booking baby spa besok jam 10',
            ])
            ->assertOk()
            ->assertSee('booking_request')
            ->assertSee('Booking Draft Approval');

        $this->assertSame(0, AiExtractedData::count());
    }

    public function test_admin_can_edit_before_approve_booking(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [$customer, $conversation, $message] = $this->seedMessage('628123450097');
        $oldService = Service::create(['name' => 'Baby Spa Basic', 'duration_minutes' => 30, 'price' => 150000, 'is_active' => true]);
        $newService = Service::create(['name' => 'Baby Spa Premium', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_request',
            'confidence_score' => 0.91,
            'extracted_booking_data' => ['service_id' => $oldService->id, 'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00'],
            'raw_ai_response' => ['intent' => 'booking_request'],
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => ['service_id' => $oldService->id, 'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00']],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.edit-approve', $approval), [
                'service_id' => $newService->id,
                'booking_date' => now()->addDays(2)->toDateString(),
                'start_time' => '14:30',
            ])
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'service_id' => $newService->id,
            'booking_date' => now()->addDays(2)->startOfDay()->format('Y-m-d H:i:s'),
            'start_time' => '14:30:00',
            'source' => 'ai_approval',
        ]);
        $this->assertNotNull($approval->fresh()->edited_data);
    }

    private function seedMessage(string $phone = '628123450098'): array
    {
        $customer = Customer::create([
            'name' => 'Bunda Rina',
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $phone.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-ui-'.$phone,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'Saya mau booking baby spa besok jam 10.',
            'sent_at' => now(),
        ]);

        return [$customer, $conversation, $message];
    }
}
