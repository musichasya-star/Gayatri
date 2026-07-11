<?php

namespace App\Services\AI;

use App\Models\AiExtractedData;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Message;
use App\Models\Service;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AiDataExtractionService
{
    private ?array $activeServicesCache = null;

    private function isBenchmarkBaseline(): bool
    {
        return config('app.query_benchmark_mode') === 'baseline';
    }

    public function extractFromMessage(Message $message): AiExtractedData
    {
        $message->loadMissing('conversation.customer');

        $result = $this->extractForMessage($message);

        return AiExtractedData::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'customer_id' => $message->customer_id,
            'intent' => $result['intent'],
            'confidence_score' => $result['confidence_score'],
            'extracted_customer_data' => $result['customer'],
            'extracted_booking_data' => $result['booking'],
            'extracted_followup_data' => $result['followup'],
            'missing_fields' => $result['missing_fields'],
            'raw_ai_response' => $result,
            'status' => 'extracted',
        ]);
    }

    public function extractForMessage(Message $message): array
    {
        $message->loadMissing('conversation.customer');
        $text = trim((string) $message->content);

        return $this->applyConversationContext($message, $this->extract($text), $text);
    }

    public function extract(string $text): array
    {
        $normalized = $this->normalizeServiceTerms($text);
        $service = $this->isBenchmarkBaseline() ? $this->detectServiceFromDb($normalized) : $this->detectServiceFromCache($normalized);
        $intent = $this->detectIntent($normalized, $service);
        $bookingDate = $this->detectBookingDate($normalized);
        $startTime = $this->detectTime($normalized);
        $slotData = $this->detectSlot($service, $bookingDate, $startTime);
        $name = $this->detectName($text);
        $babyAgeMonths = $this->detectBabyAgeMonths($normalized);
        $address = $this->detectAddress($text);

        $customer = array_filter([
            'name' => $name,
            'baby_age_months' => $babyAgeMonths,
            'address' => $address,
            'interest' => $service?->name,
            'tags' => $service ? ['interested_'.Str::slug($service->name, '_')] : null,
        ], fn ($value) => $value !== null && $value !== []);

        $booking = array_filter([
            'service_id' => $service?->id,
            'service_name' => $service?->name,
            'booking_date' => $bookingDate,
            'start_time' => $startTime,
            'availability_slot_id' => $slotData['slot_id'] ?? null,
            'branch_id' => $slotData['branch_id'] ?? null,
            'therapist_id' => $slotData['therapist_id'] ?? null,
            'slot_status' => $slotData['slot_status'] ?? null,
            'alternative_slots' => $slotData['alternatives'] ?? null,
            'source' => 'ai_automation',
        ], fn ($value) => $value !== null && $value !== '');

        if (array_keys($booking) === ['source']) {
            $booking = [];
        }

        $missingFields = [];
        if ($intent === 'booking_request') {
            foreach (['service_id', 'booking_date', 'start_time'] as $field) {
                if (empty($booking[$field])) {
                    $missingFields[] = $field;
                }
            }
        }

        return [
            'intent' => $intent,
            'confidence_score' => $this->confidenceFor($intent, $customer, $booking, $missingFields),
            'should_escalate' => in_array($intent, ['medical', 'complaint', 'refund'], true),
            'booking_confirmation' => null,
            'customer' => $customer,
            'booking' => $booking,
            'followup' => [
                'needed' => in_array($intent, ['pricing_info', 'booking_request', 'booking_cancel_request'], true) && $missingFields !== [],
                'reason' => $missingFields !== [] ? 'missing_booking_fields' : null,
            ],
            'missing_fields' => $missingFields,
        ];
    }

    private function detectIntent(string $text, ?Service $service = null): string
    {
        if (Str::contains($text, ['demam', 'obat', 'diagnosis', 'dokter', 'kejang', 'diare', 'muntah'])) {
            return 'medical';
        }

        if (Str::contains($text, ['batalkan booking', 'batal booking', 'batalkan reservasi', 'batal reservasi', 'cancel booking', 'cancel reservasi', 'mau batal', 'ingin batal', 'pembatalan', 'dibatalkan', 'batalkan'])) {
            return 'booking_cancel_request';
        }

        if (Str::contains($text, ['refund', 'uang kembali'])) {
            return 'refund';
        }

        if (Str::contains($text, ['komplain', 'kecewa', 'marah'])) {
            return 'complaint';
        }

        if (Str::contains($text, ['ubah jadwal', 'ganti jadwal', 'reschedule', 'pindah jam', 'pindah tanggal', 'majuin jadwal', 'mundurin jadwal', 'ganti menjadi', 'ubah menjadi', 'menjadi jam', 'jadi jam'])) {
            return 'booking_reschedule_request';
        }

        if ($this->isBookingLookupRequest($text)) {
            return 'booking_lookup_request';
        }

        if ($this->isOperationalInquiry($text)) {
            return 'general_inquiry';
        }

        if (Str::contains($text, ['booking', 'jadwal', 'reservasi', 'besok', 'jam ', 'pukul '])
            || ($service && Str::contains($text, ['mau', 'ingin', 'pilih', 'ambil', 'pesan']))
        ) {
            return 'booking_request';
        }

        if (Str::contains($text, ['harga', 'berapa', 'promo', 'diskon'])) {
            return 'pricing_info';
        }

        return 'general_inquiry';
    }

    private function detectService(string $text): ?Service
    {
        return $this->detectServiceFromCache($text);
    }

    private function normalizeServiceTerms(string $text): string
    {
        return Str::of(str_replace(['pijet', 'pijit'], 'pijat', Str::lower($text)))->squish()->toString();
    }

    private function detectServiceFromDb(string $text): ?Service
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->first(function (Service $service) use ($text) {
                return $this->serviceNameMatches($text, $service)
                    || ($service->category && Str::contains($text, Str::lower(str_replace('-', ' ', (string) $service->category))));
            });
    }

    private function detectServiceFromCache(string $text): ?Service
    {
        return collect($this->activeServices())->first(function (Service $service) use ($text) {
            return $this->serviceNameMatches($text, $service)
                || ($service->category && Str::contains($text, Str::lower(str_replace('-', ' ', $service->category))));
        });
    }

    private function serviceNameMatches(string $text, Service $service): bool
    {
        $name = Str::of($service->name)->lower()->squish()->toString();
        $baseName = Str::of(preg_replace('/\s*[\(\-].*$/', '', $name))->squish()->toString();

        return Str::contains($text, $name)
            || ($baseName !== '' && Str::contains($text, $baseName));
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

    private function detectName(string $text): ?string
    {
        if (preg_match('/nama\s*[:=]\s*([^\r\n]{2,40})/i', $text, $matches)) {
            return trim(preg_replace('/\s+(alamat|layanan|tanggal|jam|reservasi).*/i', '', $matches[1]));
        }

        if (preg_match('/nama\s+([^,\r\n]{2,40})/i', $text, $matches)) {
            return $this->validDetectedName(trim($matches[1]));
        }

        if (preg_match('/(?:saya|nama saya|aku)\s+([A-Za-z\s]{2,40})/i', $text, $matches)) {
            $name = trim(preg_replace('/\s+(mau|ingin|tanya|booking).*/i', '', $matches[1]));

            return $this->validDetectedName($name);
        }

        return null;
    }

    private function validDetectedName(string $name): ?string
    {
        $name = trim($name, " \t\n\r\0\x0B,.");
        $normalized = Str::of($name)->lower()->squish()->toString();

        if ($normalized === '' || in_array($normalized, ['mau', 'ingin', 'tanya', 'booking'], true)) {
            return null;
        }

        if (Str::contains($normalized, ['mau ', 'ingin ', 'tanya ', 'booking', 'reservasi', 'baby spa', 'mom massage', 'hari ini', 'besok', 'tanggal', ' jam', ' pukul'])) {
            return null;
        }

        return $name;
    }

    private function detectBabyAgeMonths(string $text): ?int
    {
        if (preg_match('/(\d{1,2})\s*bulan/', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function detectAddress(string $text): ?string
    {
        if (preg_match('/alamat\s*[:=]\s*([^\r\n]{3,120})/i', $text, $matches)) {
            return trim(preg_replace('/\s+(layanan|tanggal|jam|reservasi)\s*[:=].*/i', '', $matches[1]));
        }

        if (preg_match('/alamat\s+([^,\r\n]{3,120})/i', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/(?:alamat|di)\s+([A-Za-z0-9\s.,-]{3,80})/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function detectBookingDate(string $text): ?string
    {
        if (Str::contains($text, ['hari ini', 'hr ini', 'sekarang'])) {
            return now()->toDateString();
        }

        if (Str::contains($text, 'besok')) {
            return now()->addDay()->toDateString();
        }

        if (preg_match('/(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)/i', $text, $matches)) {
            $month = array_search(Str::lower($matches[2]), [
                1 => 'januari',
                2 => 'februari',
                3 => 'maret',
                4 => 'april',
                5 => 'mei',
                6 => 'juni',
                7 => 'juli',
                8 => 'agustus',
                9 => 'september',
                10 => 'oktober',
                11 => 'november',
                12 => 'desember',
            ], true);

            return Carbon::create(now()->year, (int) $month, (int) $matches[1])->toDateString();
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $text, $matches)) {
            return Carbon::parse($matches[1])->toDateString();
        }

        return null;
    }

    private function detectTime(string $text): ?string
    {
        if (preg_match_all('/(?:jam|pukul)(?:\s+reservasi)?\s*[:=]?\s*(\d{1,2})(?:[.:](\d{2}))?/', $text, $matches, PREG_SET_ORDER)) {
            $matches = end($matches);
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;

            if ($hour >= 1 && $hour <= 11 && Str::contains($text, ['siang', 'sore', 'malam'])) {
                $hour += 12;
            }

            return sprintf('%02d:%02d:00', $hour, $minute);
        }

        return null;
    }

    private function applyConversationContext(Message $message, array $result, string $text): array
    {
        if (! $message->conversation_id) {
            return $this->refreshBookingContext($result);
        }

        $normalized = Str::lower($text);
        $booking = $result['booking'] ?? [];
        $customerData = $result['customer'] ?? [];
        $customer = $message->conversation?->customer;
        $activeBooking = $this->isBenchmarkBaseline() ? null : $this->activeBookingFor($message);

        if ($this->isRejection($normalized)) {
            $result['booking_confirmation'] = 'rejected';
        }

        if ($customer) {
            if (empty($customerData['name']) && $customer->name && ! str_starts_with($customer->name, 'Customer ')) {
                $customerData['name'] = $customer->name;
            }

            if (empty($customerData['address']) && $customer->address) {
                $customerData['address'] = $customer->address;
            }
        }

        $lastAiReply = ($this->isAffirmation($normalized) || $this->isRejection($normalized))
            ? $this->lastBookingReply($message)
            : Message::query()
                ->where('conversation_id', $message->conversation_id)
                ->where('direction', 'outgoing')
                ->where('sender_type', 'ai')
                ->where('id', '<', $message->id)
                ->latest('id')
                ->value('content');

        if ($lastAiReply && ($this->isAffirmation($normalized) || $this->isRejection($normalized))) {
            $context = $this->extract($lastAiReply);
            $booking = array_replace($context['booking'] ?? [], $booking);
            $customerData = array_replace($context['customer'] ?? [], $customerData);
            $result['intent'] = in_array($context['intent'] ?? null, ['booking_reschedule_request', 'booking_cancel_request'], true) ? $context['intent'] : 'booking_request';
            $result['booking_confirmation'] = $this->isRejection($normalized) ? 'rejected' : 'confirmed';
        }

        $latestContext = AiExtractedData::query()
            ->select('id', 'intent', 'extracted_booking_data', 'extracted_customer_data')
            ->where('conversation_id', $message->conversation_id)
            ->whereIn('intent', ['booking_request', 'booking_reschedule_request', 'booking_cancel_request', 'booking_clarification_required'])
            ->where('message_id', '<', $message->id)
            ->latest('id')
            ->first();

        $latestBooking = $latestContext?->extracted_booking_data ?? [];
        $latestCustomer = $this->isBenchmarkBaseline()
            ? (AiExtractedData::query()
                ->where('conversation_id', $message->conversation_id)
                ->whereIn('intent', ['booking_request', 'booking_reschedule_request', 'booking_cancel_request', 'booking_clarification_required'])
                ->where('message_id', '<', $message->id)
                ->latest('id')
                ->value('extracted_customer_data') ?? [])
            : ($latestContext?->extracted_customer_data ?? []);

        if (($result['intent'] ?? null) === 'booking_request' && $this->isRescheduleChoice($normalized)) {
            $result['intent'] = 'booking_reschedule_request';
        }

        if (($result['intent'] ?? null) === 'booking_request' && $this->isNewBookingChoice($normalized)) {
            $booking['action'] = 'create_booking_draft';
        }

        if (($result['intent'] ?? null) === 'booking_request'
            && $latestContext?->intent === 'booking_reschedule_request'
            && ! $this->isNewBookingChoice($normalized)
        ) {
            $result['intent'] = 'booking_reschedule_request';
        }

        if (($result['intent'] ?? null) === 'booking_reschedule_request') {
            $activeBooking ??= $this->activeBookingFor($message);

            if ($activeBooking) {
                $booking['action'] = 'reschedule_booking';
                $booking['booking_id'] = $activeBooking->id;
                $booking['booking_code'] = $activeBooking->booking_code;
                $booking['current_booking_date'] = $activeBooking->booking_date?->toDateString();
                $booking['current_start_time'] = substr((string) $activeBooking->start_time, 0, 8);
                if (! empty($booking['start_time']) && empty($booking['booking_date'])) {
                    $booking['booking_date'] = $activeBooking->booking_date?->toDateString();
                }
                $booking['service_id'] ??= $activeBooking->service_id;
                $booking['service_name'] ??= $activeBooking->service?->name;
                $booking['branch_id'] ??= $activeBooking->branch_id;
                $booking['therapist_id'] ??= $activeBooking->therapist_id;
            }
        }

        if (($result['intent'] ?? null) === 'booking_request' && $this->needsBookingClarification($normalized, $latestBooking, $activeBooking, $message)) {
            $activeBooking ??= $this->activeBookingFor($message);

            if ($activeBooking) {
                $booking['action'] = 'clarify_booking_or_reschedule';
                $booking['active_booking_id'] = $activeBooking->id;
                $booking['active_booking_code'] = $activeBooking->booking_code;
                $booking['active_booking_date'] = $activeBooking->booking_date?->toDateString();
                $booking['active_start_time'] = substr((string) $activeBooking->start_time, 0, 8);
                $booking['active_service_name'] = $activeBooking->service?->name;
                $result['intent'] = 'booking_clarification_required';
            }
        }

        if (($result['intent'] ?? null) === 'booking_cancel_request') {
            $activeBooking ??= $this->activeBookingFor($message);

            if ($activeBooking) {
                $booking['action'] = 'cancel_booking';
                $booking['booking_id'] = $activeBooking->id;
                $booking['booking_code'] = $activeBooking->booking_code;
                $booking['booking_status'] = $activeBooking->status;
                $booking['service_id'] ??= $activeBooking->service_id;
                $booking['service_name'] ??= $activeBooking->service?->name;
                $booking['booking_date'] ??= $activeBooking->booking_date?->toDateString();
                $booking['start_time'] ??= substr((string) $activeBooking->start_time, 0, 8);
                $booking['availability_slot_id'] ??= $activeBooking->availability_slot_id;
                $booking['branch_id'] ??= $activeBooking->branch_id;
                $booking['therapist_id'] ??= $activeBooking->therapist_id;
            }
        }

        $shouldCarryLatestBooking = ! $this->isThanks($normalized)
            && (! $this->isNewBookingChoice($normalized) || $latestContext?->intent === 'booking_clarification_required')
            && (
                $booking !== []
                || ($result['intent'] ?? null) === 'booking_cancel_request'
                || $this->isAffirmation($normalized)
                || $this->isRejection($normalized)
                || $this->isNewBookingChoice($normalized)
                || $this->isRescheduleChoice($normalized)
                || $latestContext?->intent === 'booking_clarification_required'
            );
        if ($shouldCarryLatestBooking) {
            foreach (['action', 'booking_id', 'booking_code', 'booking_status', 'current_booking_date', 'current_start_time', 'active_booking_id', 'active_booking_code', 'active_booking_date', 'active_start_time', 'active_service_name', 'service_id', 'service_name', 'booking_date', 'start_time', 'availability_slot_id', 'branch_id', 'therapist_id'] as $field) {
                if (empty($booking[$field]) && ! empty($latestBooking[$field])) {
                    $booking[$field] = $latestBooking[$field];
                }
            }
        }

        if (($result['intent'] ?? null) === 'general_inquiry' && ! $this->isThanks($normalized) && $latestContext?->intent === 'booking_request' && $booking !== []) {
            $result['intent'] = 'booking_request';
        }

        foreach (['name', 'address', 'interest', 'tags'] as $field) {
            if (empty($customerData[$field]) && ! empty($latestCustomer[$field])) {
                $customerData[$field] = $latestCustomer[$field];
            }
        }

        if ($booking !== []) {
            $result['booking'] = $booking;
            $result['customer'] = $customerData;
            $result['intent'] = $result['intent'] === 'general_inquiry' && ($this->isAffirmation($normalized) || $this->isRejection($normalized))
                ? match ($booking['action'] ?? null) {
                    'reschedule_booking' => 'booking_reschedule_request',
                    'cancel_booking' => 'booking_cancel_request',
                    'clarify_booking_or_reschedule' => 'booking_clarification_required',
                    default => 'booking_request',
                }
            : $result['intent'];
        }

        if ($customerData !== []) {
            $result['customer'] = $customerData;
        }

        return $this->refreshBookingContext($result);
    }

    private function refreshBookingContext(array $result): array
    {
        $booking = $result['booking'] ?? [];
        $customer = $result['customer'] ?? [];
        $service = ! empty($booking['service_id']) ? Service::find($booking['service_id']) : null;
        $slotData = $this->detectSlot($service, $booking['booking_date'] ?? null, $booking['start_time'] ?? null);
        $booking = array_filter($booking + [
            'availability_slot_id' => $slotData['slot_id'] ?? null,
            'branch_id' => $slotData['branch_id'] ?? null,
            'therapist_id' => $slotData['therapist_id'] ?? null,
            'slot_status' => $slotData['slot_status'] ?? null,
            'alternative_slots' => $slotData['alternatives'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $missingFields = [];
        if (($result['intent'] ?? null) === 'booking_request') {
            foreach (['name', 'address'] as $field) {
                if (empty($customer[$field])) {
                    $missingFields[] = $field;
                }
            }

            foreach (['service_id', 'booking_date', 'start_time'] as $field) {
                if (empty($booking[$field])) {
                    $missingFields[] = $field;
                }
            }
        }

        if (($result['intent'] ?? null) === 'booking_reschedule_request') {
            foreach (['booking_id', 'booking_date', 'start_time'] as $field) {
                if (empty($booking[$field])) {
                    $missingFields[] = $field;
                }
            }
        }

        if (($result['intent'] ?? null) === 'booking_cancel_request') {
            foreach (['booking_id'] as $field) {
                if (empty($booking[$field])) {
                    $missingFields[] = $field;
                }
            }
        }

        $result['booking'] = $booking;
        $result['missing_fields'] = $missingFields;
        $result['followup'] = [
            'needed' => in_array($result['intent'] ?? null, ['pricing_info', 'booking_request', 'booking_reschedule_request', 'booking_cancel_request'], true) && $missingFields !== [],
            'reason' => $missingFields !== [] ? 'missing_booking_fields' : null,
        ];
        $result['confidence_score'] = $this->confidenceFor($result['intent'] ?? 'general_inquiry', $result['customer'] ?? [], $booking, $missingFields);

        return $result;
    }

    private function isAffirmation(string $text): bool
    {
        $text = Str::of($text)->lower()->squish()->toString();

        return in_array($text, ['iya', 'iya lanjutkan', 'iya batalkan', 'ya batalkan', 'ya', 'ya saya', 'saya mau', 'lanjut', 'lanjutkan', 'boleh', 'ok', 'oke', 'setuju', 'baik proses', 'proses', 'sudah benar', 'benar'], true)
            || Str::startsWith($text, ['iya ', 'oke ', 'ok ', 'lanjutkan ', 'baik proses', 'sudah benar']);
    }

    private function isRejection(string $text): bool
    {
        return in_array(trim($text), ['tidak', 'tidak jadi', 'batal', 'cancel', 'jangan'], true);
    }

    private function isThanks(string $text): bool
    {
        return Str::contains(Str::lower($text), ['terima kasih', 'makasih', 'thanks', 'thank you']);
    }

    private function isOperationalInquiry(string $text): bool
    {
        return Str::contains(Str::lower($text), ['jam buka', 'jam tutup', 'jam operasional', 'operasional jam', 'buka jam', 'tutup jam']);
    }

    private function lastBookingReply(Message $message): ?string
    {
        return Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'outgoing')
            ->where('sender_type', 'ai')
            ->where('id', '<', $message->id)
            ->where(function ($query) {
                $query->where('content', 'like', '%booking%')
                    ->orWhere('content', 'like', '%reservasi%')
                    ->orWhere('content', 'like', '%layanan%')
                    ->orWhere('content', 'like', '%tanggal%')
                    ->orWhere('content', 'like', '%alamat%')
                    ->orWhere('content', 'like', '%slot%');
            })
            ->latest('id')
            ->value('content');
    }

    private function detectSlot(?Service $service, ?string $bookingDate, ?string $startTime): array
    {
        if (! $service || ! $bookingDate || ! $startTime) {
            return [];
        }

        $slot = AvailabilitySlot::query()
            ->where('service_id', $service->id)
            ->whereDate('slot_date', $bookingDate)
            ->whereIn('start_time', [$startTime, substr($startTime, 0, 5)])
            ->orderByRaw("status = 'available' desc")
            ->first();

        if ($slot && $slot->status === AvailabilitySlotStatus::AVAILABLE && $slot->booked_count < $slot->capacity) {
            return [
                'slot_id' => $slot->id,
                'branch_id' => $slot->branch_id,
                'therapist_id' => $slot->therapist_id,
                'slot_status' => AvailabilitySlotStatus::AVAILABLE,
            ];
        }

        return [
            'slot_status' => $slot ? $slot->status : 'not_found',
            'alternatives' => $this->alternativeSlots($service, $bookingDate),
        ];
    }

    private function alternativeSlots(Service $service, string $bookingDate): array
    {
        return AvailabilitySlot::query()
            ->where('service_id', $service->id)
            ->whereDate('slot_date', $bookingDate)
            ->where('status', AvailabilitySlotStatus::AVAILABLE)
            ->whereColumn('booked_count', '<', 'capacity')
            ->orderBy('start_time')
            ->limit(3)
            ->get(['id', 'slot_date', 'start_time', 'end_time'])
            ->map(fn (AvailabilitySlot $slot) => [
                'availability_slot_id' => $slot->id,
                'slot_date' => $slot->slot_date?->toDateString(),
                'start_time' => substr((string) $slot->start_time, 0, 5),
                'end_time' => substr((string) $slot->end_time, 0, 5),
            ])
            ->all();
    }

    private function confidenceFor(string $intent, array $customer, array $booking, array $missingFields): float
    {
        if (in_array($intent, ['medical', 'complaint', 'refund'], true)) {
            return 0.95;
        }

        if ($intent === 'booking_request') {
            return $missingFields === [] ? 0.9 : 0.72;
        }

        if ($intent === 'booking_reschedule_request') {
            return $missingFields === [] ? 0.9 : 0.72;
        }

        if ($intent === 'booking_cancel_request') {
            return $missingFields === [] ? 0.9 : 0.72;
        }

        if ($intent === 'booking_lookup_request') {
            return 0.9;
        }

        if ($intent === 'booking_clarification_required') {
            return 0.9;
        }

        return ($customer !== [] || $booking !== []) ? 0.78 : 0.6;
    }

    private function needsBookingClarification(string $text, array $latestBooking = [], ?Booking $activeBooking = null, ?Message $message = null): bool
    {
        if (! $activeBooking && $message && $this->isBenchmarkBaseline()) {
            $activeBooking = $this->activeBookingFor($message);
        }

        return ! $this->isNewBookingChoice($text)
            && ! $this->isRescheduleChoice($text)
            && ! $this->isAffirmation($text)
            && ! $this->isRejection($text)
            && ($latestBooking['action'] ?? null) !== 'create_booking_draft'
            && (bool) $activeBooking;
    }

    private function isNewBookingChoice(string $text): bool
    {
        $text = Str::of($text)->lower()->squish()->toString();

        return Str::contains($text, ['booking baru', 'reservasi baru', 'jadwal baru', 'tambah reservasi', 'tambah booking', 'buat booking lagi', 'buat reservasi lagi']);
    }

    private function isRescheduleChoice(string $text): bool
    {
        $text = Str::of($text)->lower()->squish()->toString();

        return Str::contains($text, ['ubah jadwal', 'ganti jadwal', 'reschedule', 'pindah jam', 'pindah tanggal', 'ubah reservasi', 'ganti reservasi', 'ganti menjadi', 'ubah menjadi', 'menjadi jam', 'jadi jam']);
    }

    private function isBookingLookupRequest(string $text): bool
    {
        $text = Str::of($text)->lower()->squish()->toString();

        return Str::contains($text, [
            'booking saya',
            'reservasi saya',
            'data booking',
            'data reservasi',
            'detail booking',
            'detail reservasi',
            'cek booking',
            'cek reservasi',
            'status booking',
            'status reservasi',
            'punya booking',
            'punya reservasi',
            'jadwal saya',
        ]);
    }

    private function activeBookingFor(Message $message): ?Booking
    {
        if (! $message->customer_id) {
            return null;
        }

        return Booking::query()
            ->with('service')
            ->where(function ($query) use ($message) {
                $query->where('customer_id', $message->customer_id)
                    ->orWhere('conversation_id', $message->conversation_id);
            })
            ->whereIn('status', BookingStatus::active())
            ->where(function ($query) {
                $query->whereDate('booking_date', '>=', now()->toDateString())
                    ->orWhereNull('booking_date');
            })
            ->latest('booking_date')
            ->latest('start_time')
            ->latest('id')
            ->first();
    }
}
