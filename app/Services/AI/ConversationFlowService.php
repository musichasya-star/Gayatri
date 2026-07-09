<?php

namespace App\Services\AI;

use App\Models\AiExtractedData;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\Message;
use App\Models\Promo;
use App\Models\Service;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use Illuminate\Support\Str;

class ConversationFlowService
{
    private const CANCEL_WORDS = ['batal', 'tidak jadi', 'ga jadi', 'nggak jadi', 'gak jadi', 'lain kali saja', 'cancel', 'jangan dulu', 'nanti saja'];

    private ?array $activeServicesCache = null;
    private array $textExtractionCache = [];
    private array $availabilitySlotsCache = [];
    private array $managedSlotsCache = [];
    private array $slotCache = [];

    private function isBenchmarkBaseline(): bool
    {
        return config('app.query_benchmark_mode') === 'baseline';
    }

    public function __construct(
        private readonly AiDataExtractionService $extractionService,
        private readonly AiAutomationExecutorService $executorService,
    ) {}

    public function handle(Conversation $conversation, Message $message): ?array
    {
        $text = Str::of((string) $message->content)->lower()->squish()->toString();
        $flow = $this->activeFlow($conversation);

        if ($flow && $this->isCancel($text)) {
            $flow->update([
                'status' => 'cancelled',
                'last_message_id' => $message->id,
                'completed_message_id' => $message->id,
                'completed_at' => now(),
            ]);

            return $this->reply('Baik Bunda, proses reservasi tidak saya lanjutkan. Kalau nanti ingin booking lagi, Bunda bisa chat kami kapan saja ya.');
        }

        if (! $flow) {
            $flow = $this->startFlowIfNeeded($conversation, $message, $text);
            if (! $flow) {
                return null;
            }

            return $this->initialReply($flow);
        }

        return match ($flow->intent) {
            'booking_new' => $this->handleBookingNew($flow, $message),
            'booking_reschedule' => $this->handleReschedule($flow, $message),
            default => null,
        };
    }

    public function shouldSkipExtraction(Message $message): bool
    {
        $text = Str::of((string) $message->content)->lower()->squish()->toString();

        if ($this->isNewBookingIntent($text) || $this->isRescheduleIntent($text)) {
            return true;
        }

        return ConversationFlow::query()
            ->where('conversation_id', $message->conversation_id)
            ->where(function ($query) use ($message) {
                $query->where('status', 'active')
                    ->orWhere('completed_message_id', $message->id);
            })
            ->exists();
    }

    private function startFlowIfNeeded(Conversation $conversation, Message $message, string $text): ?ConversationFlow
    {
        if ($this->isRescheduleIntent($text)) {
            $booking = $this->activeBookingFor($conversation);
            if (! $booking) {
                ConversationFlow::create([
                    'conversation_id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'last_message_id' => $message->id,
                    'intent' => 'booking_reschedule',
                    'step' => 'completed',
                    'status' => 'cancelled',
                    'payload' => [],
                    'attempts' => [],
                    'completed_message_id' => $message->id,
                    'completed_at' => now(),
                ]);

                return null;
            }

            return ConversationFlow::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'last_message_id' => $message->id,
                'intent' => 'booking_reschedule',
                'step' => 'ask_date',
                'status' => 'active',
                'payload' => $this->bookingPayload($booking),
                'attempts' => [],
            ]);
        }

        if ($this->isNewBookingIntent($text)) {
            return ConversationFlow::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'last_message_id' => $message->id,
                'intent' => 'booking_new',
                'step' => 'ask_name',
                'status' => 'active',
                'payload' => [],
                'attempts' => [],
            ]);
        }

        return null;
    }

    private function initialReply(ConversationFlow $flow): array
    {
        if ($flow->intent === 'booking_reschedule') {
            $payload = $flow->payload ?? [];

            return $this->reply('Bisa Bunda, saya bantu ubah jadwal reservasi yang aktif ya.'
                ."\nKode booking: ".($payload['booking_code'] ?? '-')
                ."\nLayanan: ".($payload['service_name'] ?? '-')
                ."\nJadwal saat ini: ".($payload['current_booking_date'] ?? '-').' '.substr((string) ($payload['current_start_time'] ?? ''), 0, 5)
                ."\n\nTanggal baru yang Bunda inginkan kapan? Contoh: hari ini, besok, lusa, atau 28 Juni.");
        }

        return $this->reply('Baik Bunda, saya bantu buat reservasi baru ya. Boleh saya catat atas nama siapa?');
    }

    private function handleBookingNew(ConversationFlow $flow, Message $message): array
    {
        return match ($flow->step) {
            'ask_name' => $this->stepName($flow, $message),
            'ask_whatsapp' => $this->stepWhatsapp($flow, $message),
            'ask_address' => $this->stepAddress($flow, $message),
            'ask_service' => $this->stepService($flow, $message),
            'ask_date' => $this->stepDate($flow, $message),
            'ask_time' => $this->stepTime($flow, $message),
            'confirm' => $this->stepConfirm($flow, $message),
            default => $this->reply('Baik Bunda, boleh mulai lagi dengan menulis booking baru ya.'),
        };
    }

    private function handleReschedule(ConversationFlow $flow, Message $message): array
    {
        return match ($flow->step) {
            'ask_date' => $this->stepRescheduleDate($flow, $message),
            'ask_time' => $this->stepTime($flow, $message),
            'confirm' => $this->stepConfirm($flow, $message),
            default => $this->reply('Baik Bunda, boleh tuliskan jadwal baru yang diinginkan ya.'),
        };
    }

    private function stepName(ConversationFlow $flow, Message $message): array
    {
        if ($questionReply = $this->flowQuestionReply($flow, $message)) {
            return $questionReply;
        }

        $name = $this->extractName((string) $message->content);
        if (! $name) {
            return $this->invalid($flow, $message, 'Maaf Bunda, nama belum terbaca dengan jelas. Boleh tuliskan nama reservasi saja? Contoh: Krisna');
        }

        return $this->advance($flow, $message, ['name' => $name], 'ask_whatsapp', 'Terima kasih Bunda '.$name.'. Boleh tuliskan nomor WhatsApp aktif untuk konfirmasi booking? Contoh: 081234567890.');
    }

    private function stepWhatsapp(ConversationFlow $flow, Message $message): array
    {
        if ($questionReply = $this->flowQuestionReply($flow, $message)) {
            return $questionReply;
        }

        $whatsapp = $this->normalizeWhatsappNumber((string) $message->content);
        if (! $whatsapp) {
            return $this->invalid($flow, $message, 'Nomor WhatsApp belum terbaca, Bunda. Boleh tuliskan nomor aktif dengan angka saja? Contoh: 081234567890.');
        }

        return $this->advance($flow, $message, ['whatsapp_number' => $whatsapp, 'phone' => $whatsapp], 'ask_address', 'Baik Bunda, nomor WhatsApp kami catat +'.$whatsapp.'. Alamat lengkapnya di mana ya?');
    }

    private function stepAddress(ConversationFlow $flow, Message $message): array
    {
        if ($questionReply = $this->flowQuestionReply($flow, $message)) {
            return $questionReply;
        }

        $address = trim((string) $message->content);
        if (mb_strlen($address) < 5 || $this->isServiceLike($address)) {
            return $this->invalid($flow, $message, 'Alamatnya masih terlalu singkat, Bunda. Boleh tuliskan alamat lengkap atau area rumahnya?');
        }

        return $this->advance($flow, $message, ['address' => $address], 'ask_service', 'Baik Bunda. Untuk layanan, Bunda ingin Baby Spa Premium, Mom Massage, atau layanan lain?');
    }

    private function stepService(ConversationFlow $flow, Message $message): array
    {
        $service = $this->serviceFromText((string) $message->content);
        if (! $service) {
            if ($questionReply = $this->flowQuestionReply($flow, $message)) {
                return $questionReply;
            }

            return $this->invalid($flow, $message, 'Layanan tersebut belum saya temukan, Bunda. Saat ini tersedia: '.$this->activeServicesText().'. Bunda pilih yang mana?');
        }

        return $this->advance($flow, $message, ['service_id' => $service->id, 'service_name' => $service->name], 'ask_date', 'Siap Bunda, untuk tanggal kunjungannya kapan? Boleh tulis seperti: hari ini, besok, lusa, atau 28 Juni. Kalau ingin lihat jadwal ready, Bunda bisa tanya "jadwal yang tersedia".');
    }

    private function stepDate(ConversationFlow $flow, Message $message): array
    {
        $date = $this->dateFromText((string) $message->content);
        if (! $date) {
            if ($this->isAvailabilityQuestion((string) $message->content)) {
                return $this->inform($flow, $message, 'Untuk '.($flow->payload['service_name'] ?? 'layanan ini').', jadwal ready terdekat: '.$this->nearestAvailableSlotsText($flow).'. Bunda mau pilih tanggal yang mana?');
            }

            if ($questionReply = $this->flowQuestionReply($flow, $message)) {
                return $questionReply;
            }

            return $this->invalid($flow, $message, 'Tanggalnya belum terbaca, Bunda. Boleh tulis seperti: hari ini, besok, lusa, atau 28 Juni.');
        }

        return $this->advance($flow, $message, ['booking_date' => $date], 'ask_time', 'Baik Bunda. Untuk jam reservasinya ingin pukul berapa? Contoh: jam 2 siang atau pukul 14.00. Kalau ingin lihat jam ready, Bunda bisa tanya "yang ready jam berapa saja".');
    }

    private function stepRescheduleDate(ConversationFlow $flow, Message $message): array
    {
        $date = $this->dateFromText((string) $message->content);
        $time = $this->timeFromText((string) $message->content);

        if (! $date && $time) {
            $date = $flow->payload['current_booking_date'] ?? null;
        }

        if (! $date) {
            if ($this->isAvailabilityQuestion((string) $message->content)) {
                return $this->inform($flow, $message, 'Untuk '.($flow->payload['service_name'] ?? 'layanan ini').', jadwal ready terdekat: '.$this->nearestAvailableSlotsText($flow).'. Bunda mau pilih tanggal yang mana?');
            }

            if ($questionReply = $this->flowQuestionReply($flow, $message)) {
                return $questionReply;
            }

            return $this->invalid($flow, $message, 'Boleh tuliskan tanggal baru reservasinya, Bunda? Contoh: hari ini, besok, lusa, atau 28 Juni.');
        }

        $updates = ['booking_date' => $date];
        if ($time) {
            $updates['start_time'] = $time;
        }

        $nextStep = $time ? 'ask_time' : 'ask_time';
        $reply = $time
            ? $this->validateSlotAndMaybeConfirm($flow, $message, $updates)
            : $this->advance($flow, $message, $updates, $nextStep, 'Baik Bunda. Untuk jam barunya ingin pukul berapa? Contoh: jam 2 siang atau pukul 14.00.');

        return $reply;
    }

    private function stepTime(ConversationFlow $flow, Message $message): array
    {
        $text = (string) $message->content;

        if ($this->isAvailabilityQuestion($text)) {
            return $this->availabilityQuestionReply($flow, $message, $text);
        }

        if ($this->isAmbiguousTimeText($text)) {
            return $this->inform($flow, $message, 'Maksud Bunda jam '.((int) $this->timeFromText($text)).' pagi atau jam '.((int) $this->timeFromText($text)).' sore? Jam yang masih ready: '.$this->availableTimesText($flow).'. Bunda bisa balas misalnya "jam 3 sore".');
        }

        $time = $this->timeFromText($text) ?: ($flow->payload['start_time'] ?? null);
        if (! $time) {
            if ($questionReply = $this->flowQuestionReply($flow, $message)) {
                return $questionReply;
            }

            return $this->invalid($flow, $message, 'Jamnya belum terbaca, Bunda. Boleh tulis seperti: jam 2 siang atau pukul 14.00.');
        }

        return $this->validateSlotAndMaybeConfirm($flow, $message, ['start_time' => $time]);
    }

    private function validateSlotAndMaybeConfirm(ConversationFlow $flow, Message $message, array $updates): array
    {
        $payload = array_replace($flow->payload ?? [], $updates);
        unset($payload['availability_slot_id'], $payload['branch_id'], $payload['therapist_id']);

        $service = ! empty($payload['service_id']) ? Service::find($payload['service_id']) : null;
        $date = $payload['booking_date'] ?? null;
        $time = $payload['start_time'] ?? null;

        if (! $service || ! $date || ! $time) {
            return $this->advance($flow, $message, $updates, 'ask_time', 'Baik Bunda. Untuk jam reservasinya ingin pukul berapa?');
        }

        $slot = $this->slotForServiceDateTime($service, $date, $time);

        if (! $slot && $this->hasManagedSlotsForServiceDate($service->id, $date)) {
            $alternatives = $this->alternativeSlotsText($service, $date);

            if ($alternatives === 'belum ada slot tersedia') {
                return $this->noAvailableSlotsForDate($flow, $message, $service, $date);
            }

            return $this->inform($flow, $message, 'Jam '.substr((string) $time, 0, 5).' belum tersedia, Bunda. Jam yang masih ready: '.$alternatives.'. Bunda pilih jam yang mana?');
        }

        if (! $slot) {
            return $this->noAvailableSlotsForDate($flow, $message, $service, $date);
        }

        if ($slot && ($slot->status !== AvailabilitySlotStatus::AVAILABLE || $slot->booked_count >= $slot->capacity)) {
            $alternatives = $this->alternativeSlotsText($service, $date);

            if ($alternatives === 'belum ada slot tersedia') {
                return $this->noAvailableSlotsForDate($flow, $message, $service, $date);
            }

            return $this->inform($flow, $message, 'Slot jam '.substr((string) $time, 0, 5).' sudah penuh, Bunda. Yang masih tersedia: '.$alternatives.'. Bunda pilih jam yang mana?');
        }

        if (! $this->slotMatchesServiceDuration($service, $slot)) {
            return $this->inform($flow, $message, 'Slot jam '.substr((string) $time, 0, 5).' belum sesuai durasi layanan, Bunda. Jam yang masih ready: '.$this->alternativeSlotsText($service, $date).'. Bunda pilih jam yang mana?');
        }

        if ($slot) {
            $payload['availability_slot_id'] = $slot->id;
            $payload['branch_id'] = $slot->branch_id;
            $payload['therapist_id'] = $slot->therapist_id;
        }

        $flow->update([
            'payload' => $payload,
            'step' => 'confirm',
            'last_message_id' => $message->id,
            'attempts' => $flow->attempts ?? [],
        ]);

        return $this->reply($this->summaryReply($flow->fresh()));
    }

    private function slotForServiceDateTime(Service $service, string $date, string $time): ?AvailabilitySlot
    {
        $key = $service->id.'|'.$date.'|'.substr((string) $time, 0, 5);

        if ($this->isBenchmarkBaseline()) {
            return AvailabilitySlot::query()
                ->where('service_id', $service->id)
                ->whereDate('slot_date', $date)
                ->whereIn('start_time', array_unique([$time, substr((string) $time, 0, 5)]))
                ->orderByRaw("status = 'available' desc")
                ->first();
        }

        if (! array_key_exists($key, $this->slotCache)) {
            $timeCandidates = [
                $time,
                substr((string) $time, 0, 5),
            ];

            $this->slotCache[$key] = AvailabilitySlot::query()
                ->where('service_id', $service->id)
                ->whereDate('slot_date', $date)
                ->whereIn('start_time', array_unique($timeCandidates))
                ->orderByRaw("status = 'available' desc")
                ->first();
        }

        return $this->slotCache[$key];
    }

    private function noAvailableSlotsForDate(ConversationFlow $flow, Message $message, Service $service, string $date): array
    {
        $payload = $flow->payload ?? [];
        unset($payload['booking_date'], $payload['start_time'], $payload['availability_slot_id'], $payload['branch_id'], $payload['therapist_id']);

        $flow->update([
            'payload' => $payload,
            'step' => 'ask_date',
            'last_message_id' => $message->id,
            'attempts' => $flow->attempts ?? [],
        ]);

        return $this->reply('Jadwal '.$service->name.' tanggal '.$date.' masih kosong, Bunda. Silakan pilih tanggal lain, atau Bunda bisa tanya "jadwal yang tersedia".');
    }

    private function slotMatchesServiceDuration(Service $service, AvailabilitySlot $slot): bool
    {
        $start = strtotime(substr((string) $slot->start_time, 0, 5));
        $end = strtotime(substr((string) $slot->end_time, 0, 5));

        if ($start === false || $end === false) {
            return false;
        }

        return ($end - $start) === ((int) $service->duration_minutes * 60);
    }

    private function hasManagedSlotsForServiceDate(int $serviceId, string $date): bool
    {
        $key = $serviceId.'|'.$date;

        if ($this->isBenchmarkBaseline()) {
            return AvailabilitySlot::query()
                ->where('service_id', $serviceId)
                ->whereDate('slot_date', $date)
                ->exists();
        }

        if (! array_key_exists($key, $this->managedSlotsCache)) {
            $this->managedSlotsCache[$key] = AvailabilitySlot::query()
                ->where('service_id', $serviceId)
                ->whereDate('slot_date', $date)
                ->exists();
        }

        return $this->managedSlotsCache[$key];
    }

    private function stepConfirm(ConversationFlow $flow, Message $message): array
    {
        $text = Str::of((string) $message->content)->lower()->squish()->toString();

        if ($this->isAvailabilityQuestion($text)) {
            return $this->availabilityQuestionReply($flow, $message, (string) $message->content);
        }

        if ($this->timeFromText((string) $message->content)) {
            if ($this->isAmbiguousTimeText((string) $message->content)) {
                return $this->inform($flow, $message, 'Maksud Bunda jam '.((int) $this->timeFromText((string) $message->content)).' pagi atau jam '.((int) $this->timeFromText((string) $message->content)).' sore? Jam yang masih ready: '.$this->availableTimesText($flow).'. Bunda bisa balas misalnya "jam 3 sore".');
            }

            return $this->validateSlotAndMaybeConfirm($flow, $message, ['start_time' => $this->timeFromText((string) $message->content)]);
        }

        if (! $this->isAffirmation($text)) {
            if ($questionReply = $this->flowQuestionReply($flow, $message)) {
                return $questionReply;
            }

            return $this->invalid($flow, $message, 'Untuk memproses reservasi, Bunda bisa balas "lanjutkan". Kalau tidak jadi, balas "batal" ya.');
        }

        if ($slotReply = $this->guardSlotBeforeConfirmation($flow, $message)) {
            return $slotReply;
        }

        $payload = $flow->payload ?? [];
        $intent = $flow->intent === 'booking_reschedule' ? 'booking_reschedule_request' : 'booking_request';
        $booking = array_filter([
            'action' => $flow->intent === 'booking_reschedule' ? 'reschedule_booking' : 'create_booking_draft',
            'booking_id' => $payload['booking_id'] ?? null,
            'booking_code' => $payload['booking_code'] ?? null,
            'current_booking_date' => $payload['current_booking_date'] ?? null,
            'current_start_time' => $payload['current_start_time'] ?? null,
            'service_id' => $payload['service_id'] ?? null,
            'service_name' => $payload['service_name'] ?? null,
            'booking_date' => $payload['booking_date'] ?? null,
            'start_time' => $payload['start_time'] ?? null,
            'availability_slot_id' => $payload['availability_slot_id'] ?? null,
            'branch_id' => $payload['branch_id'] ?? null,
            'therapist_id' => $payload['therapist_id'] ?? null,
            'source' => 'ai_flow',
        ], fn ($value) => $value !== null && $value !== '');

        $customer = array_filter([
            'name' => $payload['name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'whatsapp_number' => $payload['whatsapp_number'] ?? null,
            'address' => $payload['address'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $raw = [
            'intent' => $intent,
            'confidence_score' => 0.95,
            'booking_confirmation' => 'confirmed',
            'customer' => $customer,
            'booking' => $booking,
            'followup' => ['needed' => false, 'reason' => null],
            'missing_fields' => [],
        ];

        $extracted = AiExtractedData::create([
            'conversation_id' => $flow->conversation_id,
            'message_id' => $message->id,
            'customer_id' => $flow->customer_id,
            'intent' => $intent,
            'confidence_score' => 0.95,
            'extracted_customer_data' => $customer,
            'extracted_booking_data' => $booking,
            'extracted_followup_data' => ['needed' => false, 'reason' => null],
            'missing_fields' => [],
            'raw_ai_response' => $raw,
            'status' => 'extracted',
        ]);
        $automationLog = $this->executorService->process($extracted);

        if (! $automationLog || in_array($automationLog->status, ['failed', 'skipped', 'blocked'], true)) {
            $flow->update([
                'last_message_id' => $message->id,
                'attempts' => $flow->attempts ?? [],
            ]);

            return $this->reply('Maaf Bunda, reservasi belum berhasil diproses dari sistem. Saya bantu teruskan ke admin Gayatri agar dicek manual ya.');
        }

        $flow->update([
            'status' => 'completed',
            'step' => 'completed',
            'last_message_id' => $message->id,
            'completed_message_id' => $message->id,
            'completed_at' => now(),
        ]);

        $reply = $flow->intent === 'booking_reschedule'
            ? 'Siap Bunda, permintaan ubah jadwal sudah kami proses. Mohon tunggu konfirmasi dari tim Gayatri ya.'
            : 'Siap Bunda, reservasi sudah kami proses. Mohon tunggu konfirmasi dari tim Gayatri ya.';

        return $this->reply($reply);
    }

    private function guardSlotBeforeConfirmation(ConversationFlow $flow, Message $message): ?array
    {
        $payload = $flow->payload ?? [];
        $service = ! empty($payload['service_id']) ? Service::find($payload['service_id']) : null;
        $date = $payload['booking_date'] ?? null;
        $time = $payload['start_time'] ?? null;

        if (! $service || ! $date || ! $time) {
            return $this->invalid($flow, $message, 'Data jadwal belum lengkap, Bunda. Boleh pilih tanggal dan jam reservasinya lagi?');
        }

        $slot = $this->slotForServiceDateTime($service, $date, $time);

        if (
            ! $slot
            || (! empty($payload['availability_slot_id']) && (int) $payload['availability_slot_id'] !== (int) $slot->id)
            || $slot->status !== AvailabilitySlotStatus::AVAILABLE
            || $slot->booked_count >= $slot->capacity
            || ! $this->slotMatchesServiceDuration($service, $slot)
        ) {
            return $this->validateSlotAndMaybeConfirm($flow, $message, ['start_time' => $time]);
        }

        return null;
    }

    private function advance(ConversationFlow $flow, Message $message, array $updates, string $nextStep, string $reply): array
    {
        $flow->update([
            'payload' => array_replace($flow->payload ?? [], $updates),
            'step' => $nextStep,
            'last_message_id' => $message->id,
            'attempts' => $flow->attempts ?? [],
        ]);

        return $this->reply($reply);
    }

    private function invalid(ConversationFlow $flow, Message $message, string $reply): array
    {
        $attempts = $flow->attempts ?? [];
        $attempts[$flow->step] = (int) ($attempts[$flow->step] ?? 0) + 1;

        if ($attempts[$flow->step] >= 3) {
            $flow->update([
                'status' => 'escalated',
                'attempts' => $attempts,
                'last_message_id' => $message->id,
                'completed_message_id' => $message->id,
                'completed_at' => now(),
            ]);

            return $this->reply('Maaf Bunda, data masih belum terbaca. Saya bantu teruskan ke admin agar dibantu manual ya.');
        }

        $flow->update(['attempts' => $attempts, 'last_message_id' => $message->id]);

        return $this->reply($reply);
    }

    private function inform(ConversationFlow $flow, Message $message, string $reply): array
    {
        $flow->update(['last_message_id' => $message->id]);

        return $this->reply($reply);
    }

    private function activeFlow(Conversation $conversation): ?ConversationFlow
    {
        return ConversationFlow::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    private function activeBookingFor(Conversation $conversation): ?Booking
    {
        return Booking::query()
            ->with('service')
            ->where(function ($query) use ($conversation) {
                if ($conversation->customer_id) {
                    $query->where('customer_id', $conversation->customer_id);
                }

                $query->orWhere('conversation_id', $conversation->id);
            })
            ->whereIn('status', BookingStatus::active())
            ->whereDate('booking_date', '>=', now()->toDateString())
            ->latest('booking_date')
            ->latest('start_time')
            ->latest('id')
            ->first();
    }

    private function bookingPayload(Booking $booking): array
    {
        return [
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'current_booking_date' => $booking->booking_date?->toDateString(),
            'current_start_time' => substr((string) $booking->start_time, 0, 8),
            'service_id' => $booking->service_id,
            'service_name' => $booking->service?->name,
            'branch_id' => $booking->branch_id,
            'therapist_id' => $booking->therapist_id,
        ];
    }

    private function summaryReply(ConversationFlow $flow): string
    {
        $payload = $flow->payload ?? [];

        if ($flow->intent === 'booking_reschedule') {
            return 'Siap Bunda, saya catat permintaan ubah jadwalnya. Mohon cek detail berikut:'
                ."\nKode booking: ".($payload['booking_code'] ?? '-')
                ."\nLayanan: ".($payload['service_name'] ?? '-')
                ."\nJadwal lama: ".($payload['current_booking_date'] ?? '-').' '.substr((string) ($payload['current_start_time'] ?? ''), 0, 5)
                ."\nJadwal baru: ".($payload['booking_date'] ?? '-').' '.substr((string) ($payload['start_time'] ?? ''), 0, 5)
                ."\n\nJika sudah benar, balas lanjutkan. Kalau tidak jadi, balas batal ya.";
        }

        return 'Siap Bunda, data reservasinya sudah lengkap. Mohon cek detail berikut:'
            ."\nNama: ".($payload['name'] ?? '-')
            ."\nWhatsApp: ".(! empty($payload['whatsapp_number']) ? '+'.$payload['whatsapp_number'] : '-')
            ."\nAlamat: ".($payload['address'] ?? '-')
            ."\nLayanan: ".($payload['service_name'] ?? '-')
            ."\nTanggal: ".($payload['booking_date'] ?? '-')
            ."\nJam: ".substr((string) ($payload['start_time'] ?? ''), 0, 5)
            ."\n\nJika sudah benar, balas lanjutkan. Kalau tidak jadi, balas batal ya.";
    }

    private function extractName(string $text): ?string
    {
        $name = trim($text);
        $name = preg_replace('/^(?:atas\s+nama|nama\s+saya\s+adalah|nama\s+aku\s+adalah|namaku\s+adalah|nama\s+saya|nama\s+aku|nama\s+aq|saya|aku|aq|namanya|nama)\s*[:=]?\s*/i', '', $name);
        $name = is_string($name) ? trim($name) : '';
        $normalized = Str::of($name)->lower()->squish()->toString();

        if (mb_strlen($name) < 2 || mb_strlen($name) > 50 || Str::contains($normalized, ['booking', 'reservasi', 'baby spa', 'jam ', 'tanggal', 'alamat', 'layanan', 'treatment', 'tersedia', 'tanya', 'apa ', 'berapa', 'promo', 'diskon', 'voucher', 'voucer'])) {
            return null;
        }

        return trim($name, " \t\n\r\0\x0B,.");
    }

    private function flowQuestionReply(ConversationFlow $flow, Message $message): ?array
    {
        $text = Str::of((string) $message->content)->lower()->squish()->toString();

        if (! $this->isQuestionLike($text)) {
            return null;
        }

        if (Str::contains($text, ['promo', 'diskon', 'voucher', 'voucer'])) {
            return $this->inform($flow, $message, $this->activePromosText().' '.$this->currentStepPrompt($flow));
        }

        if (Str::contains($text, ['kamu siapa', 'anda siapa', 'ini siapa', 'siapa ini', 'dengan siapa', 'chat dengan siapa'])) {
            return $this->inform($flow, $message, 'Saya asisten WhatsApp Gayatri Mom & Baby Spa yang membantu info layanan dan reservasi, Bunda. '.$this->currentStepPrompt($flow));
        }

        if (Str::contains($this->normalizeServiceTerms($text), ['layanan', 'treatment', 'jasa', 'paket', 'baby spa', 'mom massage', 'massage', 'pijat', 'spa bayi'])) {
            return $this->inform($flow, $message, 'Layanan yang tersedia saat ini: '.$this->activeServicesText().'. '.$this->currentStepPrompt($flow));
        }

        if ($this->isAvailabilityQuestion($text)) {
            if (! empty($flow->payload['service_id'])) {
                return $this->inform($flow, $message, 'Jadwal ready terdekat: '.$this->nearestAvailableSlotsText($flow).'. '.$this->currentStepPrompt($flow));
            }

            return $this->inform($flow, $message, 'Untuk cek jadwal, saya perlu tahu layanan yang dipilih dulu ya Bunda. Layanan tersedia: '.$this->activeServicesText().'. '.$this->currentStepPrompt($flow));
        }

        if (Str::contains($text, ['alamat', 'lokasi', 'dimana', 'di mana', 'maps', 'map', 'cabang'])) {
            return $this->inform($flow, $message, 'Untuk alamat cabang, nanti bisa kami bantu arahkan sesuai jadwal dan layanan yang dipilih ya Bunda. '.$this->currentStepPrompt($flow));
        }

        return $this->inform($flow, $message, 'Boleh Bunda, silakan tanya dulu. '.$this->currentStepPrompt($flow));
    }

    private function currentStepPrompt(ConversationFlow $flow): string
    {
        return match ($flow->step) {
            'ask_name' => 'Boleh saya catat atas nama siapa?',
            'ask_whatsapp' => 'Boleh tuliskan nomor WhatsApp aktif untuk konfirmasi booking?',
            'ask_address' => 'Alamat lengkapnya di mana ya?',
            'ask_service' => 'Bunda pilih layanan yang mana?',
            'ask_date' => 'Untuk tanggal kunjungannya kapan?',
            'ask_time' => 'Untuk jam reservasinya ingin pukul berapa?',
            'confirm' => 'Jika detail booking sudah benar, balas lanjutkan. Kalau tidak jadi, balas batal ya.',
            default => 'Boleh lanjutkan data reservasinya ya Bunda.',
        };
    }

    private function normalizeWhatsappNumber(string $text): ?string
    {
        $number = preg_replace('/\D+/', '', $text);
        if (! is_string($number) || $number === '') {
            return null;
        }

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        if (str_starts_with($number, '8')) {
            $number = '62'.$number;
        }

        return preg_match('/^62\d{8,14}$/', $number) ? $number : null;
    }

    private function serviceFromText(string $text): ?Service
    {
        $normalizedText = $this->normalizeServiceTerms($text);

        return collect($this->activeServices())->first(function (Service $service) use ($normalizedText) {
            return $this->serviceMatchesText($normalizedText, $service);
        });
    }

    private function serviceMatchesText(string $normalizedText, Service $service): bool
    {
        $serviceName = $this->normalizeServiceTerms($service->name);
        $baseServiceName = $this->baseServiceName($serviceName);
        $categoryText = $this->normalizeServiceTerms(str_replace('-', ' ', (string) $service->category));

        return Str::contains($normalizedText, $serviceName)
            || ($baseServiceName !== '' && Str::contains($normalizedText, $baseServiceName))
            || ($categoryText !== '' && Str::contains($normalizedText, $categoryText));
    }

    private function baseServiceName(string $normalizedServiceName): string
    {
        $baseName = preg_replace('/\s*[\(\-].*$/', '', $normalizedServiceName);

        return Str::of($baseName ?: $normalizedServiceName)->squish()->toString();
    }

    private function activeServices(): array
    {
        if ($this->isBenchmarkBaseline()) {
            return Service::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->all();
        }

        if ($this->activeServicesCache !== null) {
            return $this->activeServicesCache;
        }

        $this->activeServicesCache = Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->all();

        return $this->activeServicesCache;
    }

    private function dateFromText(string $text): ?string
    {
        if (Str::contains(Str::lower($text), ['hari ini', 'hr ini', 'sekarang'])) {
            return now()->toDateString();
        }

        if (Str::contains(Str::lower($text), 'lusa')) {
            return now()->addDays(2)->toDateString();
        }

        return $this->extractFromText($text)['booking']['booking_date'] ?? null;
    }

    private function timeFromText(string $text): ?string
    {
        return $this->extractFromText($text)['booking']['start_time'] ?? null;
    }

    private function extractFromText(string $text): array
    {
        $key = Str::lower(trim($text));

        if ($this->isBenchmarkBaseline()) {
            return $this->extractionService->extract($text);
        }

        if (! array_key_exists($key, $this->textExtractionCache)) {
            $this->textExtractionCache[$key] = $this->extractionService->extract($text);
        }

        return $this->textExtractionCache[$key];
    }

    private function normalizeServiceTerms(string $text): string
    {
        return Str::of(str_replace(['pijet', 'pijit'], 'pijat', Str::lower($text)))->squish()->toString();
    }

    private function activeServicesText(): string
    {
        return collect($this->activeServices())
            ->take(5)
            ->map(fn (Service $service) => $service->name)
            ->implode(', ') ?: 'Baby Spa Premium';
    }

    private function activePromosText(): string
    {
        $promos = Promo::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('quota')->orWhereColumn('used_count', '<', 'quota');
            })
            ->orderBy('end_at')
            ->limit(3)
            ->get();

        if ($promos->isEmpty()) {
            return 'Saat ini belum ada promo aktif yang tercatat, Bunda.';
        }

        return 'Promo aktif saat ini: '.$promos->map(fn (Promo $promo) => trim($promo->title.($promo->code ? ' kode '.$promo->code : '').($promo->type === 'percent' ? ' diskon '.(float) $promo->value.'%' : ' potongan Rp'.number_format((float) $promo->value, 0, ',', '.'))))->implode(', ').'.';
    }

    private function alternativeSlotsText(Service $service, string $date): string
    {
        return $this->availableSlotsForDate((int) $service->id, $date)
            ->filter(fn (AvailabilitySlot $slot) => $slot->status === AvailabilitySlotStatus::AVAILABLE && $slot->booked_count < $slot->capacity)
            ->filter(fn (AvailabilitySlot $slot) => $this->slotMatchesServiceDuration($service, $slot))
            ->sortBy('start_time')
            ->take(3)
            ->map(fn (AvailabilitySlot $slot) => substr((string) $slot->start_time, 0, 5))
            ->implode(', ') ?: 'belum ada slot tersedia';
    }

    private function nearestAvailableSlotsText(ConversationFlow $flow): string
    {
        $serviceId = $flow->payload['service_id'] ?? null;

        if (! $serviceId) {
            return 'pilih layanan dulu ya Bunda';
        }

        $slots = AvailabilitySlot::query()
            ->where('service_id', $serviceId)
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->where('status', AvailabilitySlotStatus::AVAILABLE)
            ->whereColumn('booked_count', '<', 'capacity')
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->limit(6)
            ->get();

        return $slots->map(fn (AvailabilitySlot $slot) => $slot->slot_date?->toDateString().' jam '.substr((string) $slot->start_time, 0, 5))
            ->implode(', ') ?: 'belum ada slot tersedia';
    }

    private function availableTimesText(ConversationFlow $flow): string
    {
        $serviceId = $flow->payload['service_id'] ?? null;
        $date = $flow->payload['booking_date'] ?? null;

        if (! $serviceId || ! $date) {
            return 'pilih tanggal dulu ya Bunda';
        }

        $slots = $this->availableSlotsForDate((int) $serviceId, $date)
            ->filter(fn (AvailabilitySlot $slot) => $slot->status === AvailabilitySlotStatus::AVAILABLE && $slot->booked_count < $slot->capacity)
            ->sortBy('start_time');

        $service = Service::find($serviceId);
        if ($service) {
            $slots = $slots->filter(fn (AvailabilitySlot $slot) => $this->slotMatchesServiceDuration($service, $slot));
        }

        if ($date === now()->toDateString()) {
            $slots = $slots->filter(fn (AvailabilitySlot $slot) => $slot->start_time >= now()->format('H:i:s'));
        }

        return $slots
            ->take(10)
            ->map(fn (AvailabilitySlot $slot) => substr((string) $slot->start_time, 0, 5))
            ->implode(', ') ?: 'belum ada slot tersedia';
    }

    private function availableSlotsForDate(int $serviceId, string $date)
    {
        $key = 'service_date|'.$serviceId.'|'.$date;

        if ($this->isBenchmarkBaseline()) {
            return AvailabilitySlot::query()
                ->where('service_id', $serviceId)
                ->whereDate('slot_date', $date)
                ->orderBy('start_time')
                ->get(['id', 'slot_date', 'start_time', 'end_time', 'status', 'booked_count', 'capacity', 'branch_id', 'therapist_id'])
                ->all();
        }

        if (! array_key_exists($key, $this->availabilitySlotsCache)) {
            $this->availabilitySlotsCache[$key] = AvailabilitySlot::query()
                ->where('service_id', $serviceId)
                ->whereDate('slot_date', $date)
                ->orderBy('start_time')
                ->get(['id', 'slot_date', 'start_time', 'end_time', 'status', 'booked_count', 'capacity', 'branch_id', 'therapist_id'])
                ->all();
        }

        return collect($this->availabilitySlotsCache[$key]);
    }

    private function availabilityQuestionReply(ConversationFlow $flow, Message $message, string $text): array
    {
        $time = $this->timeFromText($text);

        if ($time && $this->isAmbiguousTimeText($text)) {
            $hour = (int) $time;
            $candidates = array_filter([
                sprintf('%02d:00:00', $hour),
                $hour <= 11 ? sprintf('%02d:00:00', $hour + 12) : null,
            ]);

            $available = [];
            foreach ($candidates as $candidate) {
                if ($this->availableSlotFor($flow, $candidate)) {
                    $available[] = substr($candidate, 0, 5);
                }
            }

            if ($available !== []) {
                return $this->inform($flow, $message, 'Untuk jam '.$hour.', slot yang cocok dan masih ready: '.implode(', ', $available).'. Kalau mau pilih, balas jelas ya, contoh: "jam '.$hour.' sore".');
            }

            return $this->inform($flow, $message, 'Untuk jam '.$hour.', saya perlu pastikan maksudnya pagi atau sore ya Bunda. Jam yang masih ready: '.$this->availableTimesText($flow).'. Bunda mau pilih jam yang mana?');
        }

        if ($time) {
            if ($this->availableSlotFor($flow, $time)) {
                return $this->inform($flow, $message, 'Jam '.substr($time, 0, 5).' masih ready Bunda. Kalau mau pilih jam ini, balas "jam '.substr($time, 0, 5).'" ya.');
            }

            return $this->inform($flow, $message, 'Jam '.substr($time, 0, 5).' belum tersedia, Bunda. Jam yang masih ready: '.$this->availableTimesText($flow).'. Bunda mau pilih jam yang mana?');
        }

        return $this->inform($flow, $message, 'Untuk tanggal '.($flow->payload['booking_date'] ?? '-').', jam yang masih ready: '.$this->availableTimesText($flow).'. Bunda mau pilih jam yang mana?');
    }

    private function availableSlotFor(ConversationFlow $flow, string $time): ?AvailabilitySlot
    {
        $serviceId = $flow->payload['service_id'] ?? null;
        $date = $flow->payload['booking_date'] ?? null;

        if (! $serviceId || ! $date) {
            return null;
        }

        $timeCandidates = [
            $time,
            substr($time, 0, 5),
        ];

        return $this->availableSlotsForDate((int) $serviceId, $date)
            ->filter(fn (AvailabilitySlot $slot) => in_array($slot->start_time, $timeCandidates, true) && $slot->status === AvailabilitySlotStatus::AVAILABLE && $slot->booked_count < $slot->capacity)
            ->sortBy('start_time')
            ->first();
    }

    private function isCancel(string $text): bool
    {
        return Str::contains($text, self::CANCEL_WORDS);
    }

    private function isAffirmation(string $text): bool
    {
        return in_array($text, ['iya', 'iya lanjutkan', 'lanjut', 'lanjutkan', 'ok', 'oke', 'sudah benar', 'benar', 'proses'], true)
            || Str::startsWith($text, ['iya ', 'oke ', 'ok ', 'lanjutkan ', 'sudah benar']);
    }

    private function isAvailabilityQuestion(string $text): bool
    {
        return Str::contains(Str::lower($text), ['ready', 'tersedia', 'kosong', 'jadwal', 'jam berapa', 'jam apa', 'jam mana', 'slot', 'ada']);
    }

    private function isQuestionLike(string $text): bool
    {
        return str_contains($text, '?')
            || Str::contains($text, ['apa ', 'apakah', 'berapa', 'gimana', 'bagaimana', 'boleh tanya', 'mau tanya', 'ingin tanya', 'aq mau tanya', 'aku mau tanya', 'tanya dulu', 'tanya dahulu', 'layanan apa', 'yang tersedia', 'promo', 'diskon', 'voucher', 'voucer']);
    }

    private function isAmbiguousTimeText(string $text): bool
    {
        $normalized = Str::lower($text);

        if (Str::contains($normalized, ['pagi', 'siang', 'sore', 'malam'])) {
            return false;
        }

        if (! preg_match('/(?:jam|pukul)\s*(\d{1,2})(?![.:]\d{2})/', $normalized, $matches)) {
            return false;
        }

        $hour = (int) $matches[1];

        return $hour >= 1 && $hour <= 11;
    }

    private function isNewBookingIntent(string $text): bool
    {
        return Str::contains($text, ['booking baru', 'reservasi baru', 'tambah booking', 'tambah reservasi', 'mau booking', 'ingin booking', 'buat booking', 'buat reservasi', 'mau reservasi', 'pesan baby spa', 'pesan treatment', 'pesan layanan', 'mau pesan', 'ingin pesan']);
    }

    private function isRescheduleIntent(string $text): bool
    {
        return Str::contains($text, ['ubah jadwal', 'ganti jadwal', 'reschedule', 'pindah jam', 'pindah tanggal', 'ganti menjadi', 'ubah menjadi', 'menjadi jam', 'jadi jam']);
    }

    private function isServiceLike(string $text): bool
    {
        return (bool) $this->serviceFromText($text);
    }

    private function reply(string $reply): array
    {
        return ['reply' => $reply, 'status' => 'success', 'fallback_reason' => null, 'sources' => [], 'confidence' => 0.95];
    }
}
