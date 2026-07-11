<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AiAutomationApproval;
use App\Models\AiAutomationLog;
use App\Models\AiExtractedData;
use App\Models\AiLog;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\Followup;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ReminderStatus;
use App\Support\CampaignStatus;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MasterDataManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_view_customer_index_but_cannot_access_branch_management(): void
    {
        $sales = User::factory()->create([
            'role' => UserRole::SALES,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($sales)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Customer');

        $this->actingAs($sales)
            ->get(route('admin.branches.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_and_update_customer_with_audit_log(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $branch = Branch::create([
            'name' => 'Cabang Bandung',
            'code' => 'BDG',
            'city' => 'Bandung',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customers.store'), [
                'branch_id' => $branch->id,
                'name' => 'Bunda Naya',
                'phone' => '081200000111',
                'whatsapp_number' => '6281200000111',
                'email' => 'naya@example.test',
                'city' => 'Bandung',
                'status' => CustomerStatus::ACTIVE,
                'tags' => 'lead-baru, baby-spa',
                'notes' => 'Suka treatment sore hari.',
            ])
            ->assertRedirect();

        $customer = Customer::where('whatsapp_number', '6281200000111')->firstOrFail();

        $this->assertSame(['lead-baru', 'baby-spa'], $customer->tags);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.customers.update', $customer), [
                'branch_id' => $branch->id,
                'name' => 'Bunda Naya Update',
                'phone' => '081200000222',
                'whatsapp_number' => '6281200000111',
                'email' => 'naya@example.test',
                'city' => 'Cimahi',
                'status' => CustomerStatus::LEAD,
                'tags' => 'followup, warm-lead',
                'notes' => 'Minta dihubungi lagi besok.',
            ])
            ->assertRedirect(route('admin.customers.show', $customer));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Bunda Naya Update',
            'city' => 'Cimahi',
            'status' => CustomerStatus::LEAD,
        ]);

        $this->assertSame(2, AuditLog::where('auditable_type', Customer::class)->where('auditable_id', $customer->id)->count());
    }

    public function test_customer_index_formats_whatsapp_numbers_and_uses_simple_pagination(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        Customer::create([
            'name' => 'Bunda Nomor Valid',
            'phone' => '081234500001',
            'whatsapp_number' => '6281234500001',
            'status' => CustomerStatus::ACTIVE,
            'tags' => ['baby-spa'],
        ]);
        $lidCustomer = Customer::create([
            'name' => 'Bunda LID',
            'phone' => null,
            'whatsapp_number' => '1234567890',
            'status' => CustomerStatus::LEAD,
        ]);
        Conversation::create([
            'customer_id' => $lidCustomer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '1234567890@lid',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('+62 812 3450 0001')
            ->assertSee('6281234500001')
            ->assertSee('081234500001')
            ->assertSee('WhatsApp ID: 1234567890')
            ->assertSee('Sebelumnya')
            ->assertSee('Berikutnya')
            ->assertDontSee('<svg', false);
    }

    public function test_admin_can_create_branch_service_and_therapist(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $therapistUser = User::factory()->create([
            'role' => UserRole::THERAPIST,
            'status' => UserStatus::ACTIVE,
            'email' => 'therapist.master@example.test',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.branches.store'), [
                'name' => 'Cabang Depok',
                'code' => 'DPK',
                'phone' => '0214000001',
                'address' => 'Jl. Melati No. 1',
                'city' => 'Depok',
                'timezone' => 'Asia/Jakarta',
                'status' => UserStatus::ACTIVE,
            ])
            ->assertRedirect(route('admin.branches.index'));

        $branch = Branch::where('code', 'DPK')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.services.store'), [
                'branch_id' => $branch->id,
                'name' => 'Mom Relax Massage',
                'category' => 'mom-care',
                'duration_minutes' => 90,
                'price' => 375000,
                'is_active' => 1,
                'description' => 'Perawatan relaksasi ibu.',
            ])
            ->assertRedirect(route('admin.services.index'));

        $service = Service::where('name', 'Mom Relax Massage')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.therapists.store'), [
                'user_id' => $therapistUser->id,
                'branch_id' => $branch->id,
                'name' => 'Rani Terapis',
                'phone' => '081300000333',
                'specialization' => 'Postnatal Massage',
                'status' => UserStatus::ACTIVE,
                'notes' => 'Terapis senior.',
            ])
            ->assertRedirect(route('admin.therapists.index'));

        $therapist = Therapist::where('name', 'Rani Terapis')->firstOrFail();

        $this->assertSame($branch->id, $service->branch_id);
        $this->assertSame($branch->id, $therapist->branch_id);
        $this->assertSame($therapistUser->id, $therapist->user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'branch.created',
            'auditable_type' => Branch::class,
            'auditable_id' => $branch->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service.created',
            'auditable_type' => Service::class,
            'auditable_id' => $service->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'therapist.created',
            'auditable_type' => Therapist::class,
            'auditable_id' => $therapist->id,
        ]);
    }

    public function test_admin_can_import_and_export_customers_csv(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        Branch::create([
            'name' => 'Cabang Bogor',
            'code' => 'BGR',
            'city' => 'Bogor',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $csv = <<<'CSV'
name,branch_code,phone,whatsapp_number,email,city,status,tags,notes
Bunda Kila,BGR,081200000444,6281200000444,kila@example.test,Bogor,active,"lead, baby-spa",Import pertama
CSV;

        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.customers.import'), [
                'csv_file' => $file,
            ])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseHas('customers', [
            'name' => 'Bunda Kila',
            'whatsapp_number' => '6281200000444',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.customers.export'));
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Bunda Kila', $content);
        $this->assertStringContainsString('branch_code', $content);
    }

    public function test_admin_can_archive_master_data_and_write_audit_logs(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $branch = Branch::create([
            'name' => 'Cabang Solo',
            'code' => 'SLO',
            'city' => 'Solo',
            'timezone' => 'Asia/Jakarta',
            'status' => UserStatus::ACTIVE,
        ]);

        $service = Service::create([
            'branch_id' => $branch->id,
            'name' => 'Baby Massage',
            'duration_minutes' => 60,
            'price' => 200000,
            'is_active' => true,
        ]);

        $therapist = Therapist::create([
            'branch_id' => $branch->id,
            'name' => 'Dina Terapis',
            'status' => UserStatus::ACTIVE,
        ]);

        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Rara',
            'whatsapp_number' => '6281200000555',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $this->actingAs($admin)->post(route('admin.branches.archive', $branch))->assertRedirect(route('admin.branches.index'));
        $this->actingAs($admin)->post(route('admin.services.archive', $service))->assertRedirect(route('admin.services.index'));
        $this->actingAs($admin)->post(route('admin.therapists.archive', $therapist))->assertRedirect(route('admin.therapists.index'));
        $this->actingAs($admin)->post(route('admin.customers.archive', $customer))->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'status' => UserStatus::INACTIVE]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => 0]);
        $this->assertDatabaseHas('therapists', ['id' => $therapist->id, 'status' => UserStatus::INACTIVE]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => CustomerStatus::INACTIVE]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.archived', 'auditable_type' => Branch::class, 'auditable_id' => $branch->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.archived', 'auditable_type' => Service::class, 'auditable_id' => $service->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'therapist.archived', 'auditable_type' => Therapist::class, 'auditable_id' => $therapist->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.archived', 'auditable_type' => Customer::class, 'auditable_id' => $customer->id]);
    }

    public function test_admin_can_delete_customer_with_related_module_data(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $branch = Branch::create(['name' => 'Cabang Delete', 'code' => 'DEL', 'status' => UserStatus::ACTIVE]);
        $service = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa Delete', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $therapist = Therapist::create(['branch_id' => $branch->id, 'name' => 'Terapis Delete', 'status' => UserStatus::ACTIVE]);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Delete',
            'phone' => '081299900001',
            'whatsapp_number' => '6281299900001',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'delete-session', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '6281299900001@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'Saya mau booking',
            'sent_at' => now(),
        ]);
        $slot = AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'availability_slot_id' => $slot->id,
            'booking_code' => 'BK-CUST-DELETE',
            'booking_date' => $slot->slot_date->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
        ]);
        $campaign = Campaign::create([
            'name' => 'Campaign Delete',
            'message_template' => 'Halo',
            'status' => CampaignStatus::DRAFT,
        ]);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'booking_id' => $booking->id]);
        Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => 'h1',
            'status' => ReminderStatus::PENDING,
            'scheduled_at' => now()->addHour(),
        ]);
        Followup::create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'title' => 'Follow-up delete', 'status' => 'open', 'priority' => 'normal']);
        Feedback::create(['customer_id' => $customer->id, 'booking_id' => $booking->id, 'rating' => 5, 'status' => 'received']);
        AiLog::create(['conversation_id' => $conversation->id, 'message_id' => $message->id, 'customer_id' => $customer->id, 'prompt' => 'Q', 'response' => 'A', 'confidence' => 0.9, 'status' => 'success']);
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_request',
            'confidence_score' => 0.9,
            'status' => 'extracted',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => ['booking_id' => $booking->id]],
            'status' => 'pending',
        ]);
        AiAutomationLog::create([
            'ai_extracted_data_id' => $extracted->id,
            'ai_automation_approval_id' => $approval->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'status' => 'pending_approval',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Hapus');

        $this->actingAs($admin)
            ->delete(route('admin.customers.destroy', $customer))
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('reminders', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('followups', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('campaign_recipients', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('feedback', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('ai_logs', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('ai_extracted_data', ['id' => $extracted->id]);
        $this->assertDatabaseMissing('ai_automation_approvals', ['id' => $approval->id]);
        $this->assertDatabaseMissing('ai_automation_logs', ['customer_id' => $customer->id]);

        $this->assertDatabaseHas('availability_slots', [
            'id' => $slot->id,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted']);
    }
}
