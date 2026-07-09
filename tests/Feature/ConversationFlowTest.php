<?php

namespace Tests\Feature;

use App\Models\AiAutomationApproval;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Promo;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AI\ConversationFlowService;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_flow_validates_each_step_and_can_be_cancelled(): void
    {
        [$customer, $conversation] = $this->conversation('628177700001');
        $flow = app(ConversationFlowService::class);

        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
        $this->assertStringContainsString('atas nama siapa', $reply['reply']);

        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'baby spa jam 3'));
        $this->assertStringContainsString('nama belum terbaca', $reply['reply']);

        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'Budi'));
        $this->assertStringContainsString('nomor WhatsApp aktif', $reply['reply']);

        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'abc123'));
        $this->assertStringContainsString('Nomor WhatsApp belum terbaca', $reply['reply']);

        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'batal'));
        $this->assertStringContainsString('tidak saya lanjutkan', $reply['reply']);
        $this->assertSame('cancelled', ConversationFlow::latest('id')->first()->status);
    }

    public function test_booking_flow_answers_questions_without_treating_them_as_form_data(): void
    {
        [$customer, $conversation] = $this->conversation('628177700006');
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Mom Massage', 'category' => 'mom-massage', 'duration_minutes' => 60, 'price' => 300000, 'is_active' => true]);
        $flow = app(ConversationFlowService::class);

        $flow->handle($conversation, $this->incoming($conversation, $customer, 'Aku mau pesan'));

        $serviceQuestion = $flow->handle($conversation, $this->incoming($conversation, $customer, 'Layanan apa yang tersedia'));
        $activeFlow = ConversationFlow::latest('id')->first();

        $this->assertStringContainsString('Layanan yang tersedia', $serviceQuestion['reply']);
        $this->assertStringContainsString('atas nama siapa', $serviceQuestion['reply']);
        $this->assertSame('ask_name', $activeFlow->step);
        $this->assertArrayNotHasKey('name', $activeFlow->payload ?? []);

        $genericQuestion = $flow->handle($conversation, $this->incoming($conversation, $customer, 'Apa sih aq mau tanya dahulu'));

        $this->assertStringContainsString('silakan tanya dulu', $genericQuestion['reply']);
        $this->assertStringContainsString('atas nama siapa', $genericQuestion['reply']);
        $this->assertSame('ask_name', ConversationFlow::latest('id')->first()->step);
        $this->assertArrayNotHasKey('name', ConversationFlow::latest('id')->first()->payload ?? []);

        Promo::create(['title' => 'Diskon Baby Spa', 'code' => 'BABY10', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $promoQuestion = $flow->handle($conversation, $this->incoming($conversation, $customer, 'ada promo kah'));

        $this->assertStringContainsString('Promo aktif saat ini', $promoQuestion['reply']);
        $this->assertStringContainsString('atas nama siapa', $promoQuestion['reply']);
        $this->assertSame('ask_name', ConversationFlow::latest('id')->first()->step);
        $this->assertArrayNotHasKey('name', ConversationFlow::latest('id')->first()->payload ?? []);

        $questionMarkOnly = $flow->handle($conversation, $this->incoming($conversation, $customer, 'ini bisa untuk bayi?'));

        $this->assertStringContainsString('silakan tanya dulu', $questionMarkOnly['reply']);
        $this->assertStringContainsString('atas nama siapa', $questionMarkOnly['reply']);
        $this->assertSame('ask_name', ConversationFlow::latest('id')->first()->step);
        $this->assertArrayNotHasKey('name', ConversationFlow::latest('id')->first()->payload ?? []);

        $identityQuestion = $flow->handle($conversation, $this->incoming($conversation, $customer, 'kamu siapa ya ?'));

        $this->assertStringContainsString('asisten WhatsApp Gayatri', $identityQuestion['reply']);
        $this->assertStringContainsString('atas nama siapa', $identityQuestion['reply']);
        $this->assertStringNotContainsString('silakan tanya dulu', $identityQuestion['reply']);
        $this->assertSame('ask_name', ConversationFlow::latest('id')->first()->step);
        $this->assertArrayNotHasKey('name', ConversationFlow::latest('id')->first()->payload ?? []);
    }

    public function test_booking_flow_matches_base_service_name_when_service_has_age_and_duration_suffix(): void
    {
        $service = Service::create([
            'name' => 'Girl Massage (13-20 Tahun) - 60Menit',
            'category' => 'girl massage',
            'duration_minutes' => 60,
            'price' => 150000,
            'is_active' => true,
        ]);

        foreach (['saya ingin girl massage', 'Girl Massage'] as $index => $serviceText) {
            [$customer, $conversation] = $this->conversation('62817770009'.($index + 1));
            $flow = app(ConversationFlowService::class);

            $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Krisna'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, '08177700099'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Kediri kota'));
            $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, $serviceText));

            $activeFlow = ConversationFlow::latest('id')->first();

            $this->assertStringContainsString('tanggal kunjungannya', $reply['reply']);
            $this->assertStringNotContainsString('Layanan tersebut belum saya temukan', $reply['reply']);
            $this->assertSame('ask_date', $activeFlow->step);
            $this->assertSame($service->id, $activeFlow->payload['service_id'] ?? null);
        }
    }

    public function test_booking_flow_extracts_name_from_common_intro_phrase(): void
    {
        [$customer, $conversation] = $this->conversation('628177700007');
        $flow = app(ConversationFlowService::class);

        $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'nama saya rianto'));

        $activeFlow = ConversationFlow::latest('id')->first();

        $this->assertSame('rianto', $activeFlow->payload['name'] ?? null);
        $this->assertStringContainsString('Terima kasih Bunda rianto', $reply['reply']);
        $this->assertStringNotContainsString('saya rianto', $reply['reply']);
        $this->assertSame('ask_whatsapp', $activeFlow->step);
    }

    public function test_booking_flow_extracts_name_from_namaku_adalah_phrase(): void
    {
        [$customer, $conversation] = $this->conversation('628177700008');
        $flow = app(ConversationFlowService::class);

        $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
        $reply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'namaku adalah rianto'));

        $activeFlow = ConversationFlow::latest('id')->first();

        $this->assertSame('rianto', $activeFlow->payload['name'] ?? null);
        $this->assertStringContainsString('Terima kasih Bunda rianto', $reply['reply']);
        $this->assertStringNotContainsString('namaku adalah rianto', $reply['reply']);
        $this->assertSame('ask_whatsapp', $activeFlow->step);
    }

    public function test_booking_flow_creates_pending_approval_after_step_confirmation(): void
    {
        [$customer, $conversation] = $this->conversation('628177700002');
        $service = $this->service();
        $slot = AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $flow = app(ConversationFlowService::class);

        $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
        $flow->handle($conversation, $this->incoming($conversation, $customer, 'Krisna'));
        $flow->handle($conversation, $this->incoming($conversation, $customer, '08177700022'));
        $flow->handle($conversation, $this->incoming($conversation, $customer, 'Tulungagung kota'));
        $flow->handle($conversation, $this->incoming($conversation, $customer, 'baby spa'));
        $flow->handle($conversation, $this->incoming($conversation, $customer, 'besok'));
        $summary = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam 2 siang'));

        $this->assertStringContainsString('data reservasinya sudah lengkap', $summary['reply']);
        $this->assertStringContainsString('14:00', $summary['reply']);

        $confirmed = $flow->handle($conversation, $this->incoming($conversation, $customer, 'lanjutkan'));

        $this->assertStringContainsString('reservasi sudah kami proses', $confirmed['reply']);
        $this->assertSame('completed', ConversationFlow::latest('id')->first()->status);
        $this->assertSame('628177700022', $customer->fresh()->whatsapp_number);
        $this->assertSame('628177700022', $customer->fresh()->phone);
        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'status' => BookingStatus::PENDING,
        ]);
        $booking = Booking::query()
            ->where('customer_id', $customer->id)
            ->where('status', BookingStatus::PENDING)
            ->where('source', 'ai_approval')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(1, AiAutomationApproval::count());

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)
            ->get(route('admin.bookings.index', [
                'date' => $booking->booking_date?->toDateString(),
                'status' => BookingStatus::PENDING,
            ]))
            ->assertOk()
            ->assertSee($booking->booking_code)
            ->assertSee($booking->customer?->name ?: $customer->name);
    }

    public function test_reschedule_flow_uses_active_booking_and_validates_new_slot(): void
    {
        [$customer, $conversation] = $this->conversation('628177700003');
        $service = $this->service();
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-FLOW-RSCH',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => 'unpaid',
            'source' => 'manual',
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
        $flow = app(ConversationFlowService::class);

        $start = $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau ubah jadwal'));
        $this->assertStringContainsString('BK-FLOW-RSCH', $start['reply']);

        $summary = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam 2 siang'));
        $this->assertStringContainsString('ubah jadwal', $summary['reply']);
        $this->assertStringContainsString('14:00', $summary['reply']);

        $confirmed = $flow->handle($conversation, $this->incoming($conversation, $customer, 'lanjutkan'));

        $this->assertStringContainsString('permintaan ubah jadwal', $confirmed['reply']);
        $approval = AiAutomationApproval::firstOrFail();
        $this->assertSame('reschedule_booking', $approval->action);
        $this->assertSame($booking->id, data_get($approval->proposed_data, 'booking.booking_id'));
        $this->assertSame($slot->id, data_get($approval->proposed_data, 'booking.availability_slot_id'));
    }

    public function test_booking_flow_understands_today_and_answers_available_times(): void
    {
        Carbon::setTestNow('2026-06-27 10:00:00');

        try {
            [$customer, $conversation] = $this->conversation('628177700004');
            $service = $this->service();
            AvailabilitySlot::create([
                'service_id' => $service->id,
                'slot_date' => now()->toDateString(),
                'start_time' => now()->addHour()->format('H:00:00'),
                'end_time' => now()->addHours(2)->format('H:00:00'),
                'capacity' => 1,
                'booked_count' => 0,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ]);
            $flow = app(ConversationFlowService::class);

            $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Krisna'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, '08177700044'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Sukorejo Indah Kediri'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'baby spa saja'));
            $dateReply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'hari ini kak'));

            $this->assertStringContainsString('jam reservasinya', $dateReply['reply']);
            $this->assertSame(now()->toDateString(), ConversationFlow::latest('id')->first()->payload['booking_date']);

            $timeReply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'yang ready jam berapa saja'));

            $this->assertStringContainsString('jam yang masih ready', $timeReply['reply']);
            $this->assertStringContainsString(now()->addHour()->format('H:00'), $timeReply['reply']);
            $this->assertSame('ask_time', ConversationFlow::latest('id')->first()->step);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_booking_flow_does_not_select_ambiguous_availability_question_and_allows_time_correction(): void
    {
        Carbon::setTestNow('2026-06-27 10:00:00');

        try {
            [$customer, $conversation] = $this->conversation('628177700005');
            $service = $this->service();
            AvailabilitySlot::create([
                'service_id' => $service->id,
                'slot_date' => now()->addDay()->toDateString(),
                'start_time' => '03:00:00',
                'end_time' => '04:00:00',
                'capacity' => 1,
                'booked_count' => 0,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ]);
            $soreSlot = AvailabilitySlot::create([
                'service_id' => $service->id,
                'slot_date' => now()->addDay()->toDateString(),
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'capacity' => 1,
                'booked_count' => 0,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ]);
            $flow = app(ConversationFlowService::class);

            $flow->handle($conversation, $this->incoming($conversation, $customer, 'saya mau booking'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Budi'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, '08177700055'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'Kediri raya'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'baby spa'));
            $flow->handle($conversation, $this->incoming($conversation, $customer, 'besok'));

            $availabilityReply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam 3 ada?'));

            $this->assertStringContainsString('15:00', $availabilityReply['reply']);
            $this->assertSame('ask_time', ConversationFlow::latest('id')->first()->step);
            $this->assertArrayNotHasKey('start_time', ConversationFlow::latest('id')->first()->payload);

            $ambiguousReply = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam 3'));

            $this->assertStringContainsString('pagi atau jam 3 sore', $ambiguousReply['reply']);
            $this->assertSame('ask_time', ConversationFlow::latest('id')->first()->step);

            $summary = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam 3 pagi'));
            $this->assertStringContainsString('Jam: 03:00', $summary['reply']);

            $corrected = $flow->handle($conversation, $this->incoming($conversation, $customer, 'jam tolong ganti jam 3 sore bukan jam 3 pagi'));

            $this->assertStringContainsString('Jam: 15:00', $corrected['reply']);
            $this->assertSame('15:00:00', ConversationFlow::latest('id')->first()->payload['start_time']);
            $this->assertSame($soreSlot->id, ConversationFlow::latest('id')->first()->payload['availability_slot_id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function conversation(string $phone): array
    {
        $customer = Customer::create([
            'name' => 'Customer '.$phone,
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::firstOrCreate(['session_name' => 'default'], ['status' => 'working']);
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

    private function incoming(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-flow-'.sha1($content.microtime(true)),
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => $content,
            'sent_at' => now(),
        ]);
    }

    private function service(): Service
    {
        return Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
    }
}
