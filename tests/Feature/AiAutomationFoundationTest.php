<?php

namespace Tests\Feature;

use App\Models\AiAutomationApproval;
use App\Models\AiAutomationRule;
use App\Models\AiLog;
use App\Models\AiPersona;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AI\AiAutomationApprovalService;
use App\Services\AI\AiAutomationExecutorService;
use App\Services\AI\AiDataExtractionService;
use App\Services\AI\AiService;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAutomationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_booking_request_and_creates_pending_approval(): void
    {
        [$customer, $conversation] = $this->seedConversation();
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AiAutomationRule::create([
            'name' => 'Create Booking Draft from Chat',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'confidence_threshold' => 0.85,
            'required_fields' => ['service_id', 'booking_date', 'start_time'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);
        $message = $this->incomingMessage($conversation, $customer, "Nama: Rina\nAlamat: Jalan Mawar 10\nLayanan: Baby Spa Premium\nTanggal reservasi: besok\nJam reservasi: 10");

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertDatabaseHas('ai_extracted_data', [
            'message_id' => $message->id,
            'intent' => 'booking_request',
            'status' => 'awaiting_confirmation',
        ]);
        $this->assertDatabaseHas('ai_automation_logs', [
            'ai_extracted_data_id' => $extracted->id,
            'status' => 'awaiting_confirmation',
        ]);
        $this->assertSame($service->id, data_get($extracted->fresh()->extracted_booking_data, 'service_id'));

        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00");
        $confirmation = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmation);

        $this->assertDatabaseHas('ai_automation_approvals', [
            'ai_extracted_data_id' => $confirmation->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'status' => 'pending',
        ]);
    }

    public function test_auto_create_rule_updates_low_risk_customer_data(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450011', 'Customer 628123450011');
        Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AiAutomationRule::create([
            'name' => 'Auto Update Customer from Chat',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'customer',
            'action' => 'update_customer',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.70,
            'required_fields' => ['name'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request', 'pricing_info', 'general_inquiry']],
            'is_active' => true,
        ]);
        $message = $this->incomingMessage($conversation, $customer, 'Halo saya Rina mau tanya baby spa untuk anak 6 bulan');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $customer->refresh();

        $this->assertSame('Rina', $customer->name);
        $this->assertContains('interested_baby_spa_premium', $customer->tags);
        $this->assertDatabaseHas('ai_automation_logs', [
            'ai_extracted_data_id' => $extracted->id,
            'target_entity' => 'customer',
            'status' => 'processed',
        ]);
    }

    public function test_medical_intent_does_not_create_approval_or_customer_update(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450012', 'Customer 628123450012');
        AiAutomationRule::create([
            'name' => 'Booking Draft Safe Only',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'confidence_threshold' => 0.50,
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);
        $message = $this->incomingMessage($conversation, $customer, 'Bayi saya demam, boleh dipijat tidak?');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertDatabaseHas('ai_extracted_data', [
            'message_id' => $message->id,
            'intent' => 'medical',
            'status' => 'extracted',
        ]);
        $this->assertDatabaseMissing('ai_automation_approvals', [
            'ai_extracted_data_id' => $extracted->id,
        ]);
        $this->assertDatabaseHas('ai_automation_logs', [
            'ai_extracted_data_id' => $extracted->id,
            'status' => 'skipped',
        ]);
    }

    public function test_auto_create_booking_uses_available_slot_when_approval_disabled(): void
    {
        config()->set('crm.ai_data_automation.booking_requires_approval', false);
        [$customer, $conversation] = $this->seedConversation('628123450013', 'Bunda Auto Booking');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $customer->update(['address' => 'Jalan Melati 12']);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        AiAutomationRule::create([
            'name' => 'Auto Booking from Chat',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => ['service_id', 'booking_date', 'start_time', 'availability_slot_id'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);
        $message = $this->incomingMessage($conversation, $customer, 'Saya mau booking baby spa besok jam 10');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertDatabaseMissing('bookings', ['conversation_id' => $conversation->id]);
        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'status' => BookingStatus::DRAFT,
            'source' => 'ai_automation',
        ]);
        $this->assertSame(1, $slot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::FULL, $slot->fresh()->status);
        $this->assertDatabaseHas('ai_automation_logs', ['ai_extracted_data_id' => $confirmed->id, 'status' => 'processed']);
    }

    public function test_booking_approval_uses_slot_when_approved(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450014', 'Bunda Approval Booking');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $customer->update(['address' => 'Jalan Anggrek 15']);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        AiAutomationRule::create([
            'name' => 'Booking Approval from Chat',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'mode' => 'need_confirmation',
            'confidence_threshold' => 0.85,
            'required_fields' => ['service_id', 'booking_date', 'start_time', 'availability_slot_id'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);
        $message = $this->incomingMessage($conversation, $customer, 'Saya mau booking baby spa besok jam 10');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);
        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);
        $approval = AiAutomationApproval::where('ai_extracted_data_id', $confirmed->id)->firstOrFail();

        app(AiAutomationApprovalService::class)->approve($approval, $admin);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'availability_slot_id' => $slot->id,
            'source' => 'ai_approval',
        ]);
        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    public function test_ai_offers_alternative_slots_when_requested_slot_is_full(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $result = app(AiService::class)->simulate('Saya mau booking baby spa besok jam 10');

        $this->assertStringContainsString('penuh', strtolower($result['reply']));
        $this->assertStringContainsString('15:00', $result['reply']);
    }

    public function test_ai_returns_text_booking_confirmation_when_booking_data_is_complete(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $result = app(AiService::class)->simulate("Nama: Putri\nAlamat: Kediri\nLayanan: Baby Spa Premium\nTanggal reservasi: besok\nJam reservasi: 4 sore");

        $this->assertStringContainsString('data reservasinya sudah lengkap', $result['reply']);
        $this->assertStringContainsString('Iya lanjutkan', $result['reply']);
        $this->assertStringContainsString('balas Tidak', $result['reply']);
        $this->assertArrayNotHasKey('buttons', $result);
    }

    public function test_short_confirmation_needs_template_when_customer_address_is_missing(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450015', 'Bunda Konfirmasi Slot');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $offeredSlot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        app(AiDataExtractionService::class)->extractFromMessage(
            $this->incomingMessage($conversation, $customer, 'Saya ingin booking baby spa besok jam 3 sore')
        );
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => 'Slot jam 3 sore penuh. Kami bisa bantu Baby Spa Premium besok pukul 16:00. Bunda mau pilih slot ini?',
            'sent_at' => now(),
        ]);
        $confirmation = $this->incomingMessage($conversation, $customer, 'Iya saya mau');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($confirmation);

        $this->assertSame('booking_request', $extracted->intent);
        $this->assertSame('16:00:00', data_get($extracted->extracted_booking_data, 'start_time'));
        $this->assertSame($offeredSlot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        $this->assertContains('address', $extracted->missing_fields);
    }

    public function test_booking_template_message_extracts_required_data_and_creates_pending_approval(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450016', 'Customer 628123450016');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $message = $this->incomingMessage($conversation, $customer, "Nama: Rina\nAlamat: Jalan Kenanga 5\nLayanan: Baby Spa Premium\nTanggal reservasi: besok\nJam reservasi: 10");

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertSame([], $extracted->missing_fields);
        $this->assertSame('Rina', data_get($extracted->extracted_customer_data, 'name'));
        $this->assertSame('Jalan Kenanga 5', data_get($extracted->extracted_customer_data, 'address'));
        $this->assertSame($slot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        $this->assertSame('awaiting_confirmation', $extracted->fresh()->status);
        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $this->assertDatabaseHas('bookings', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'status' => BookingStatus::PENDING,
            'source' => 'ai_approval',
        ]);
        $this->assertDatabaseHas('ai_automation_approvals', [
            'ai_extracted_data_id' => $confirmed->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'status' => 'pending',
        ]);
    }

    public function test_lanjutkan_alone_confirms_booking_context(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450029', 'Customer 628123450029');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $message = $this->incomingMessage($conversation, $customer, "Nama: Krisna\nAlamat: Tulungagung\nLayanan: Baby Spa Premium\nTanggal reservasi: besok\nJam reservasi: 10");

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);
        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00\nBalas dengan: Iya lanjutkan / Tidak.");

        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $this->assertSame('confirmed', data_get($confirmed->raw_ai_response, 'booking_confirmation'));
        $this->assertSame($slot->id, data_get($confirmed->extracted_booking_data, 'availability_slot_id'));
        $this->assertDatabaseHas('ai_automation_approvals', [
            'ai_extracted_data_id' => $confirmed->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'status' => 'pending',
        ]);
    }

    public function test_free_text_booking_and_confirmation_after_fallback_keep_booking_context(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450017', 'Customer 628123450017');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->year.'-06-26',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $dataMessage = $this->incomingMessage($conversation, $customer, 'Nama rizal, alamat kediri, layanan baby spa, tanggal 26 Juni, jam 3 sore');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($dataMessage);

        $this->assertSame([], $extracted->missing_fields);
        $this->assertSame('rizal', data_get($extracted->extracted_customer_data, 'name'));
        $this->assertSame('kediri', data_get($extracted->extracted_customer_data, 'address'));
        $this->assertSame($slot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        app(AiAutomationExecutorService::class)->process($extracted);
        $this->assertSame('awaiting_confirmation', $extracted->fresh()->status);
        $this->assertDatabaseMissing('bookings', ['conversation_id' => $conversation->id]);

        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: 2026-06-26\nJam: 15:00");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);
        $this->assertDatabaseHas('bookings', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'status' => BookingStatus::PENDING,
            'source' => 'ai_approval',
        ]);
        $this->assertDatabaseHas('ai_automation_approvals', [
            'ai_extracted_data_id' => $confirmed->id,
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
            'status' => 'pending',
        ]);

        app(AiAutomationExecutorService::class)->process($confirmed->fresh());
        $this->assertSame(1, AiAutomationApproval::where('ai_extracted_data_id', $confirmed->id)->count());
        $this->assertSame(1, Booking::where('conversation_id', $conversation->id)->where('status', BookingStatus::PENDING)->count());

        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => "Terima kasih, Bunda Rizal! Berikut detail booking:\n- Nama: Rizal\n- Alamat: Kediri\n- Layanan: Baby Spa Premium\n- Tanggal: 26 Juni 2026\n- Jam: 15:00 WIB\nSilakan konfirmasi jika semua sudah benar.",
            'sent_at' => now(),
        ]);
        $baikProses = app(AiDataExtractionService::class)->extractFromMessage(
            $this->incomingMessage($conversation, $customer, 'Baik proses')
        );

        $this->assertSame('booking_request', $baikProses->intent);
        $this->assertSame([], $baikProses->missing_fields);
        $this->assertSame($slot->id, data_get($baikProses->extracted_booking_data, 'availability_slot_id'));

        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => 'Mohon maaf Bunda, untuk pertanyaan tersebut saya belum bisa memastikan jawabannya.',
            'sent_at' => now(),
        ]);
        $sudahBenar = app(AiDataExtractionService::class)->extractFromMessage(
            $this->incomingMessage($conversation, $customer, 'Iya sudah benar')
        );

        $this->assertSame('booking_request', $sudahBenar->intent);
        $this->assertSame([], $sudahBenar->missing_fields);
        $this->assertSame($slot->id, data_get($sudahBenar->extracted_booking_data, 'availability_slot_id'));
    }

    public function test_explicit_booking_name_repairs_previous_bad_customer_name(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450027', 'ingin baby spa hari ini jam');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->year.'-06-26',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $message = $this->incomingMessage($conversation, $customer, 'nama krisna, alamat tulungagung, layanan baby spa, tanggal reservasi 26 juni, jam 3 sore');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertSame('krisna', data_get($extracted->extracted_customer_data, 'name'));
        $this->assertSame('krisna', $customer->fresh()->name);
        $this->assertSame('awaiting_confirmation', $extracted->fresh()->status);

        $this->outgoingAiMessage($conversation, $customer, "Data reservasi sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->year."-06-26\nJam: 15:00");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $booking = Booking::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('krisna', $booking->customer->name);
    }

    public function test_name_parser_ignores_booking_intent_as_customer_name(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450028', 'Customer 628123450028');

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'saya ingin baby spa hari ini jam 3 sore'));

        $this->assertNull(data_get($extracted->extracted_customer_data, 'name'));
    }

    public function test_chat_reschedule_creates_approval_and_updates_booking_when_approved(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450018', 'Bunda Reschedule');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $oldSlot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $newSlot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'availability_slot_id' => $oldSlot->id,
            'booking_code' => 'BK-GAY-TEST-RSCH',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Tolong ubah jadwal saya ke besok jam 4 sore');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertSame('booking_reschedule_request', $extracted->intent);
        $this->assertSame($booking->id, data_get($extracted->extracted_booking_data, 'booking_id'));
        $this->assertSame($newSlot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        $this->assertSame('awaiting_confirmation', $extracted->fresh()->status);

        $this->outgoingAiMessage($conversation, $customer, "Data ubah jadwal sudah lengkap.\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 16:00\nBalas dengan: Iya lanjutkan / Tidak.");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya lanjutkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);
        $approval = AiAutomationApproval::where('ai_extracted_data_id', $confirmed->id)->firstOrFail();

        $this->assertSame('reschedule_booking', $approval->action);
        $this->assertDatabaseHas('ai_automation_approvals', [
            'id' => $approval->id,
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
            'status' => 'pending',
        ]);

        app(AiAutomationApprovalService::class)->approve($approval, $admin);

        $booking->refresh();
        $this->assertSame($newSlot->id, $booking->availability_slot_id);
        $this->assertSame('16:00:00', substr((string) $booking->start_time, 0, 8));
        $this->assertSame(0, $oldSlot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::AVAILABLE, $oldSlot->fresh()->status);
        $this->assertSame(1, $newSlot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::FULL, $newSlot->fresh()->status);
    }

    public function test_reschedule_request_without_new_time_does_not_show_new_booking_template(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450030', 'Bunda Reschedule Start');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-RESCH-START',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'saya mau ubah jadwal');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        $reply = app(AiService::class)->generateReply($conversation, $message);

        $this->assertSame('booking_reschedule_request', $extracted->intent);
        $this->assertSame('reschedule_booking', data_get($extracted->extracted_booking_data, 'action'));
        $this->assertContains('booking_date', $extracted->missing_fields);
        $this->assertContains('start_time', $extracted->missing_fields);
        $this->assertStringContainsString('data booking sebelumnya sudah saya catat', $reply['reply']);
        $this->assertStringContainsString('hari atau tanggal kunjungan', $reply['reply']);
        $this->assertStringContainsString('perkiraan jam', $reply['reply']);
        $this->assertStringNotContainsString('Nama:', $reply['reply']);
        $this->assertStringNotContainsString('Alamat:', $reply['reply']);
    }

    public function test_reschedule_followup_time_keeps_active_booking_context(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450031', 'Bunda Reschedule Followup');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-RESCH-FOLLOW',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'saya mau ubah jadwal'));
        $this->outgoingAiMessage($conversation, $customer, 'Baik Bunda, data booking sebelumnya sudah saya catat. Tinggal lengkapi: hari atau tanggal kunjungan, perkiraan jam.');

        $message = $this->incomingMessage($conversation, $customer, 'ganti menjadi jam 2 siang');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        $reply = app(AiService::class)->generateReply($conversation, $message);

        $this->assertSame('booking_reschedule_request', $extracted->intent);
        $this->assertSame('reschedule_booking', data_get($extracted->extracted_booking_data, 'action'));
        $this->assertSame($booking->id, data_get($extracted->extracted_booking_data, 'booking_id'));
        $this->assertSame(now()->addDay()->toDateString(), data_get($extracted->extracted_booking_data, 'booking_date'));
        $this->assertSame('14:00:00', data_get($extracted->extracted_booking_data, 'start_time'));
        $this->assertSame($slot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        $this->assertSame([], $extracted->missing_fields);
        $this->assertStringContainsString('permintaan ubah jadwalnya', $reply['reply']);
        $this->assertStringContainsString('14:00', $reply['reply']);
        $this->assertStringNotContainsString('reservasi baru atau mengubah jadwal', $reply['reply']);
    }

    public function test_chat_cancel_draft_booking_changes_status_to_cancelled_by_user_after_confirmation(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450019', 'Bunda Cancel Draft');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_code' => 'BK-GAY-TEST-CNCL-D',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Saya mau batalkan reservasi saya'));
        app(AiAutomationExecutorService::class)->process($extracted);

        $this->assertSame('booking_cancel_request', $extracted->intent);
        $this->assertSame($booking->id, data_get($extracted->extracted_booking_data, 'booking_id'));
        $this->assertSame('awaiting_confirmation', $extracted->fresh()->status);

        $this->outgoingAiMessage($conversation, $customer, "Data pembatalan reservasi sudah ditemukan, Bunda.\nKode booking: BK-GAY-TEST-CNCL-D\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00\nStatus saat ini: draft\n\nBalas dengan: Iya batalkan / Tidak.");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya batalkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $this->assertSame(BookingStatus::CANCELLED_BY_USER, $booking->fresh()->status);
        $this->assertSame(0, $slot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::AVAILABLE, $slot->fresh()->status);
        $this->assertDatabaseMissing('ai_automation_approvals', [
            'ai_extracted_data_id' => $confirmed->id,
            'action' => 'cancel_booking',
        ]);
    }

    public function test_chat_cancel_confirmed_booking_requires_admin_approval(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450020', 'Bunda Cancel Confirmed');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_code' => 'BK-GAY-TEST-CNCL-C',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Tolong batalkan reservasi saya'));
        app(AiAutomationExecutorService::class)->process($extracted);
        $this->outgoingAiMessage($conversation, $customer, "Data pembatalan reservasi sudah ditemukan, Bunda.\nKode booking: BK-GAY-TEST-CNCL-C\nLayanan: Baby Spa Premium\nTanggal: ".now()->addDay()->toDateString()."\nJam: 10:00\nStatus saat ini: confirmed\n\nBalas dengan: Iya batalkan / Tidak.");
        $confirmed = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Iya batalkan'));
        app(AiAutomationExecutorService::class)->process($confirmed);

        $approval = AiAutomationApproval::where('ai_extracted_data_id', $confirmed->id)->firstOrFail();
        $this->assertSame(BookingStatus::CONFIRMED, $booking->fresh()->status);
        $this->assertSame('cancel_booking', $approval->action);
        $this->assertSame('pending', $approval->status);

        app(AiAutomationApprovalService::class)->approve($approval, $admin);

        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $slot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::AVAILABLE, $slot->fresh()->status);
    }

    public function test_active_booking_ambiguous_new_schedule_asks_for_booking_or_reschedule_clarification(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450021', 'Bunda Ambigu');
        $customer->update(['address' => 'Kediri']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-TEST-ACTIVE',
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Saya mau reservasi baby spa besok jam 3 sore');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        app(AiAutomationExecutorService::class)->process($extracted);
        $reply = app(AiService::class)->generateReply($conversation, $message);

        $this->assertSame('booking_clarification_required', $extracted->intent);
        $this->assertSame('clarify_booking_or_reschedule', data_get($extracted->extracted_booking_data, 'action'));
        $this->assertSame('extracted', $extracted->fresh()->status);
        $this->assertDatabaseMissing('ai_automation_approvals', ['ai_extracted_data_id' => $extracted->id]);
        $this->assertStringContainsString('reservasi baru atau mengubah jadwal', $reply['reply']);
        $this->assertStringContainsString('Booking baru / Ubah jadwal', $reply['reply']);
    }

    public function test_booking_baru_after_clarification_continues_as_new_booking_request(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450022', 'Bunda Booking Baru');
        $customer->update(['address' => 'Kediri']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-TEST-OLDNEW',
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Saya mau reservasi baby spa besok jam 3 sore'));
        $this->outgoingAiMessage($conversation, $customer, 'Bunda ingin membuat reservasi baru atau mengubah jadwal reservasi yang sudah ada? Balas: Booking baru / Ubah jadwal.');

        $choice = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Booking baru'));
        app(AiAutomationExecutorService::class)->process($choice);

        $this->assertSame('booking_request', $choice->intent);
        $this->assertSame($slot->id, data_get($choice->extracted_booking_data, 'availability_slot_id'));
        $this->assertNull(data_get($choice->extracted_booking_data, 'booking_id'));
        $this->assertSame('awaiting_confirmation', $choice->fresh()->status);
    }

    public function test_booking_baru_choice_is_remembered_for_next_booking_data_message(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450025', 'Bunda Booking Baru Context');
        $customer->update(['address' => 'Kediri']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-TEST-ACTIVE2',
            'booking_date' => '2026-06-26',
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->year.'-06-28',
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Saya mau reservasi baby spa tanggal 28 juni jam 4 sore'));
        $this->outgoingAiMessage($conversation, $customer, 'Bunda ingin membuat reservasi baru atau mengubah jadwal reservasi yang sudah ada? Balas: Booking baru / Ubah jadwal.');
        $choice = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'booking baru'));
        app(AiAutomationExecutorService::class)->process($choice);

        $dataMessage = $this->incomingMessage($conversation, $customer, 'nama tya, alamat kediri, tanggal 28 juni, jam 4 sore');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($dataMessage);
        $reply = app(AiService::class)->generateReply($conversation, $dataMessage);

        $this->assertSame('booking_request', $extracted->intent);
        $this->assertSame('create_booking_draft', data_get($extracted->extracted_booking_data, 'action'));
        $this->assertSame($slot->id, data_get($extracted->extracted_booking_data, 'availability_slot_id'));
        $this->assertStringNotContainsString('reservasi baru atau mengubah jadwal', $reply['reply']);
        $this->assertStringContainsString('data reservasinya sudah lengkap', $reply['reply']);
        $this->assertStringContainsString(now()->year.'-06-28', $reply['reply']);
    }

    public function test_explicit_booking_baru_does_not_reuse_previous_cancel_context(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450024', 'Putri');
        $customer->update(['address' => 'Kediri']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-TEST-OLDCTX',
            'booking_date' => '2026-06-26',
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Tolong batalkan reservasi saya'));
        $newBookingMessage = $this->incomingMessage($conversation, $customer, 'Saya mau booking baru');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($newBookingMessage);
        $reply = app(AiService::class)->generateReply($conversation, $newBookingMessage);

        $this->assertSame('booking_request', $extracted->intent);
        $this->assertNull(data_get($extracted->extracted_booking_data, 'booking_id'));
        $this->assertNull(data_get($extracted->extracted_booking_data, 'booking_date'));
        $this->assertNull(data_get($extracted->extracted_booking_data, 'start_time'));
        $this->assertContains('service_id', $extracted->missing_fields);
        $this->assertContains('booking_date', $extracted->missing_fields);
        $this->assertContains('start_time', $extracted->missing_fields);
        $this->assertStringContainsString('data booking sebelumnya sudah saya catat', $reply['reply']);
        $this->assertStringContainsString('layanan yang diinginkan', $reply['reply']);
        $this->assertStringContainsString('hari atau tanggal kunjungan', $reply['reply']);
        $this->assertStringNotContainsString('2026-06-26', $reply['reply']);
        $this->assertStringNotContainsString('16:00', $reply['reply']);
    }

    public function test_booking_lookup_returns_existing_reservation_details_without_missing_field_loop(): void
    {
        config()->set('ai.provider', 'local');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450025', 'Bunda Lookup');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-LOOKUP',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Apakah saya punya reservasi untuk besok');
        $extracted = app(AiDataExtractionService::class)->extractFromMessage($message);
        $reply = app(AiService::class)->generateReply($conversation, $message);

        $this->assertSame('booking_lookup_request', $extracted->intent);
        $this->assertSame([], $extracted->missing_fields);
        $this->assertStringContainsString('BK-GAY-LOOKUP', $reply['reply']);
        $this->assertStringContainsString('Baby Spa Premium', $reply['reply']);
        $this->assertStringContainsString('15:00', $reply['reply']);
        $this->assertStringNotContainsString('data booking sebelumnya sudah saya catat', $reply['reply']);
        $this->assertStringNotContainsString('Tinggal lengkapi', $reply['reply']);
    }

    public function test_booking_lookup_uses_local_database_even_when_external_provider_is_enabled(): void
    {
        config()->set('ai.provider', 'openrouter');
        config()->set('ai.api_key', 'test-key');
        AiPersona::create(['name' => 'Gayatri AI', 'slug' => 'gayatri-ai', 'prompt' => 'Jawab ramah.', 'tone' => 'warm', 'is_active' => true]);
        [$customer, $conversation] = $this->seedConversation('628123450026', 'Bunda Provider Lookup');
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-PROVIDER-LOOKUP',
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::PENDING,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Tolong berikan detail reservasi saya');
        $reply = app(AiService::class)->generateReply($conversation, $message);

        $this->assertDatabaseHas('ai_logs', [
            'message_id' => $message->id,
        ]);
        $this->assertSame('local', AiLog::where('message_id', $message->id)->firstOrFail()->meta['reply_source']);
        $this->assertStringContainsString('BK-GAY-PROVIDER-LOOKUP', $reply['reply']);
        $this->assertStringContainsString('pending', $reply['reply']);
        $this->assertStringNotContainsString('Tinggal lengkapi', $reply['reply']);
    }

    public function test_ubah_jadwal_after_clarification_continues_as_reschedule_request(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123450023', 'Bunda Pilih Reschedule');
        $customer->update(['address' => 'Kediri']);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-TEST-OLDRS',
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Saya mau reservasi baby spa besok jam 3 sore'));
        $this->outgoingAiMessage($conversation, $customer, 'Bunda ingin membuat reservasi baru atau mengubah jadwal reservasi yang sudah ada? Balas: Booking baru / Ubah jadwal.');

        $choice = app(AiDataExtractionService::class)->extractFromMessage($this->incomingMessage($conversation, $customer, 'Ubah jadwal'));
        app(AiAutomationExecutorService::class)->process($choice);

        $this->assertSame('booking_reschedule_request', $choice->intent);
        $this->assertSame($booking->id, data_get($choice->extracted_booking_data, 'booking_id'));
        $this->assertSame($slot->id, data_get($choice->extracted_booking_data, 'availability_slot_id'));
        $this->assertSame('awaiting_confirmation', $choice->fresh()->status);
    }

    private function seedConversation(string $phone = '628123450010', string $name = 'Customer 628123450010'): array
    {
        $customer = Customer::create([
            'name' => $name,
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

        return [$customer, $conversation];
    }

    private function incomingMessage(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-auto-'.sha1($content),
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => $content,
            'sent_at' => now(),
        ]);
    }

    private function outgoingAiMessage(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => $content,
            'sent_at' => now(),
        ]);
    }
}
