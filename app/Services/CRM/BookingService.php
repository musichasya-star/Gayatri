<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Promo;
use App\Models\Service;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\PaymentStatus;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private readonly ReminderService $reminderService,
        private readonly FeedbackService $feedbackService,
    ) {}

    public function create(array $data, ?int $userId): Booking
    {
        $service = Service::where('is_active', true)->find($data['service_id']);

        if (! $service) {
            throw new InvalidArgumentException('Layanan tidak aktif atau tidak ditemukan.');
        }

        $bookingDate = Carbon::parse($data['booking_date'])->startOfDay();
        if ($bookingDate->isPast() && ! $bookingDate->isToday()) {
            throw new InvalidArgumentException('Tanggal booking tidak boleh di masa lalu.');
        }

        $startTime = Carbon::createFromFormat('H:i', substr($data['start_time'], 0, 5));
        $endTime = (clone $startTime)->addMinutes((int) $service->duration_minutes);

        $this->validateOperatingHours($startTime, $endTime);

        if (! empty($data['therapist_id']) && $this->hasConflict((int) $data['therapist_id'], $bookingDate->toDateString(), $startTime->format('H:i:s'), $endTime->format('H:i:s'))) {
            throw new InvalidArgumentException('Jadwal terapis bentrok dengan booking lain.');
        }

        $slot = $this->resolveSlot($data, $service->id, $bookingDate->toDateString(), $startTime->format('H:i:s'), $endTime->format('H:i:s'));
        $promo = $this->validPromo($data['promo_id'] ?? null);

        $booking = Booking::create([
            'customer_id' => $data['customer_id'],
            'branch_id' => $data['branch_id'] ?? $service->branch_id,
            'service_id' => $service->id,
            'therapist_id' => $data['therapist_id'] ?? null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'created_by' => $userId,
            'promo_id' => $promo?->id,
            'availability_slot_id' => $slot?->id,
            'booking_code' => $this->bookingCode(),
            'booking_date' => $bookingDate->toDateString(),
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'status' => $data['status'] ?? BookingStatus::DRAFT,
            'payment_status' => $data['payment_status'] ?? PaymentStatus::UNPAID,
            'source' => $data['source'] ?? 'manual',
            'promo_discount' => $this->discountAmount($promo, (float) $service->price),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($promo) {
            $promo->increment('used_count');
        }

        if ($slot && ! in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW], true)) {
            $this->reserveSlot($slot);
        }

        $this->markCampaignBooked($booking);

        if ($booking->status === BookingStatus::CONFIRMED) {
            $this->reminderService->syncForConfirmedBooking($booking);
            $this->sendConfirmation($booking);
        }

        if ($booking->status === BookingStatus::COMPLETED) {
            $this->feedbackService->requestForBooking($booking);
        }

        return $booking;
    }

    public function update(Booking $booking, array $data): Booking
    {
        $service = Service::where('is_active', true)->find($data['service_id']);

        if (! $service) {
            throw new InvalidArgumentException('Layanan tidak aktif atau tidak ditemukan.');
        }

        $bookingDate = Carbon::parse($data['booking_date'])->startOfDay();
        if ($bookingDate->isPast() && ! $bookingDate->isToday()) {
            throw new InvalidArgumentException('Tanggal booking tidak boleh di masa lalu.');
        }

        $startTime = Carbon::createFromFormat('H:i', substr($data['start_time'], 0, 5));
        $endTime = (clone $startTime)->addMinutes((int) $service->duration_minutes);

        $this->validateOperatingHours($startTime, $endTime);

        if (! empty($data['therapist_id']) && $this->hasConflict((int) $data['therapist_id'], $bookingDate->toDateString(), $startTime->format('H:i:s'), $endTime->format('H:i:s'), $booking->id)) {
            throw new InvalidArgumentException('Jadwal terapis bentrok dengan booking lain.');
        }

        $oldPromoId = $booking->promo_id;
        $oldSlot = $booking->availabilitySlot;
        $promo = $this->validPromo($data['promo_id'] ?? null, $oldPromoId);
        $slot = $this->resolveSlot($data, $service->id, $bookingDate->toDateString(), $startTime->format('H:i:s'), $endTime->format('H:i:s'), $booking);

        $oldStatus = $booking->status;
        $oldBookingDate = $booking->booking_date?->toDateString();
        $oldStartTime = substr((string) $booking->start_time, 0, 5);
        $wasConfirmed = $oldStatus === BookingStatus::CONFIRMED;
        $wasCompleted = $oldStatus === BookingStatus::COMPLETED;

        $booking->update([
            'customer_id' => $data['customer_id'],
            'branch_id' => $data['branch_id'] ?? $service->branch_id,
            'service_id' => $service->id,
            'therapist_id' => $data['therapist_id'] ?? null,
            'promo_id' => $promo?->id,
            'availability_slot_id' => $slot?->id,
            'booking_date' => $bookingDate->toDateString(),
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'promo_discount' => $this->discountAmount($promo, (float) $service->price),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncPromoUsage($oldPromoId, $promo?->id);
        $this->syncSlotUsage($oldSlot, $slot, $booking);

        if (! $wasConfirmed && $booking->status === BookingStatus::CONFIRMED) {
            $this->reminderService->syncForConfirmedBooking($booking);
            $this->sendConfirmation($booking);
        }

        $scheduleChanged = $oldBookingDate !== $booking->booking_date?->toDateString() || $oldStartTime !== substr((string) $booking->start_time, 0, 5);
        $sentConfirmationForStatusChange = ! $wasConfirmed && $booking->status === BookingStatus::CONFIRMED;
        if ($scheduleChanged && ! $sentConfirmationForStatusChange && ! in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW], true)) {
            if ($booking->status === BookingStatus::CONFIRMED) {
                $this->reminderService->syncForConfirmedBooking($booking);
            }

            $this->sendRescheduleNotice($booking, $oldBookingDate, $oldStartTime);
        }

        if (! $wasCompleted && $booking->status === BookingStatus::COMPLETED) {
            $this->feedbackService->requestForBooking($booking);
        }

        if ($oldStatus !== $booking->status && in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER], true)) {
            $this->sendCancellationNotice($booking);
        }

        if (in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::RESCHEDULED], true)) {
            $this->reminderService->cancelForBooking($booking);
        }

        return $booking;
    }

    public function cancel(Booking $booking, string $status = BookingStatus::CANCELLED, ?string $note = null): Booking
    {
        if (! in_array($status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER], true)) {
            throw new InvalidArgumentException('Status pembatalan tidak valid.');
        }

        $oldSlot = $booking->availabilitySlot;
        $booking->update([
            'status' => $status,
            'notes' => trim(($booking->notes ? $booking->notes."\n" : '').($note ?: 'Booking dibatalkan.')),
        ]);

        $this->releaseSlot($oldSlot);
        $this->reminderService->cancelForBooking($booking);
        $this->sendCancellationNotice($booking->refresh());

        return $booking->refresh();
    }

    public function hasConflict(int $therapistId, string $bookingDate, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool
    {
        return Booking::query()
            ->where('therapist_id', $therapistId)
            ->whereDate('booking_date', $bookingDate)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW])
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            })
            ->exists();
    }

    private function bookingCode(): string
    {
        do {
            $code = 'BK-GAY-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    private function validateOperatingHours(Carbon $startTime, Carbon $endTime): void
    {
        $open = Carbon::createFromFormat('H:i', (string) config('crm.booking_open_time', '09:00'));
        $close = Carbon::createFromFormat('H:i', (string) config('crm.booking_close_time', '18:00'));

        if ($startTime->lt($open) || $endTime->gt($close)) {
            throw new InvalidArgumentException('Jam booking harus berada dalam jam operasional '.config('crm.booking_open_time').' - '.config('crm.booking_close_time').'.');
        }
    }

    private function resolveSlot(array $data, int $serviceId, string $date, string $startTime, string $endTime, ?Booking $currentBooking = null): ?AvailabilitySlot
    {
        $slot = ! empty($data['availability_slot_id'])
            ? AvailabilitySlot::find((int) $data['availability_slot_id'])
            : AvailabilitySlot::query()
                ->whereDate('slot_date', $date)
                ->where('service_id', $serviceId)
                ->whereIn('start_time', [$startTime, substr($startTime, 0, 5)])
                ->when(! empty($data['branch_id']), fn ($query) => $query->where(function ($inner) use ($data) {
                    $inner->whereNull('branch_id')->orWhere('branch_id', $data['branch_id']);
                }))
                ->when(! empty($data['therapist_id']), fn ($query) => $query->where(function ($inner) use ($data) {
                    $inner->whereNull('therapist_id')->orWhere('therapist_id', $data['therapist_id']);
                }))
                ->orderByRaw('therapist_id is null')
                ->first();

        if (! $slot && ! empty($data['availability_slot_id'])) {
            throw new InvalidArgumentException('Slot jadwal belum tersedia. Silakan input jadwal tersedia terlebih dahulu.');
        }

        if (! $slot) {
            $hasManagedSlots = AvailabilitySlot::query()
                ->whereDate('slot_date', $date)
                ->where('service_id', $serviceId)
                ->exists();

            if ($hasManagedSlots) {
                throw new InvalidArgumentException('Slot jadwal belum tersedia untuk jam tersebut. Pilih slot lain atau input jadwal tersedia terlebih dahulu.');
            }

            return null;
        }

        if ($slot->slot_date->toDateString() !== $date || $slot->service_id !== $serviceId || $this->normalizeTime((string) $slot->start_time) !== $startTime) {
            throw new InvalidArgumentException('Slot jadwal tidak sesuai dengan layanan, tanggal, atau jam booking.');
        }

        if ($slot->status === AvailabilitySlotStatus::BLOCKED) {
            throw new InvalidArgumentException('Slot jadwal sedang diblokir.');
        }

        $remaining = $slot->capacity - $slot->booked_count;
        $sameSlot = $currentBooking && $currentBooking->availability_slot_id === $slot->id;
        if ($remaining <= 0 && ! $sameSlot) {
            throw new InvalidArgumentException('Slot jadwal sudah penuh.');
        }

        if ($this->normalizeTime((string) $slot->end_time) !== $endTime) {
            throw new InvalidArgumentException('Durasi layanan tidak sesuai dengan slot jadwal.');
        }

        return $slot;
    }

    private function reserveSlot(AvailabilitySlot $slot): void
    {
        $slot->increment('booked_count');
        $slot->refresh();
        if ($slot->booked_count >= $slot->capacity) {
            $slot->update(['status' => AvailabilitySlotStatus::FULL]);
        }
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }

    private function releaseSlot(?AvailabilitySlot $slot): void
    {
        if (! $slot) {
            return;
        }

        if ($slot->booked_count > 0) {
            $slot->decrement('booked_count');
        }

        $slot->refresh();
        if ($slot->status === AvailabilitySlotStatus::FULL && $slot->booked_count < $slot->capacity) {
            $slot->update(['status' => AvailabilitySlotStatus::AVAILABLE]);
        }
    }

    private function syncSlotUsage(?AvailabilitySlot $oldSlot, ?AvailabilitySlot $newSlot, Booking $booking): void
    {
        $shouldReserve = ! in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW], true);

        if (! $shouldReserve) {
            $this->releaseSlot($oldSlot);

            return;
        }

        if ($oldSlot?->id === $newSlot?->id) {
            return;
        }

        $this->releaseSlot($oldSlot);

        if ($newSlot) {
            $this->reserveSlot($newSlot);
        }
    }

    private function validPromo(?int $promoId, ?int $currentPromoId = null): ?Promo
    {
        if (! $promoId) {
            return null;
        }

        $promo = Promo::find($promoId);
        if (! $promo || ($promo->id !== $currentPromoId && ! $promo->isUsable())) {
            throw new InvalidArgumentException('Promo tidak aktif, expired, atau kuota habis.');
        }

        return $promo;
    }

    private function discountAmount(?Promo $promo, float $price): float
    {
        if (! $promo) {
            return 0;
        }

        if ($promo->type === 'percent') {
            return min($price, round($price * ((float) $promo->value / 100), 2));
        }

        return min($price, (float) $promo->value);
    }

    private function syncPromoUsage(?int $oldPromoId, ?int $newPromoId): void
    {
        if ($oldPromoId === $newPromoId) {
            return;
        }

        if ($oldPromoId) {
            Promo::whereKey($oldPromoId)->where('used_count', '>', 0)->decrement('used_count');
        }

        if ($newPromoId) {
            Promo::whereKey($newPromoId)->increment('used_count');
        }
    }

    private function markCampaignBooked(Booking $booking): void
    {
        $campaignRecipient = CampaignRecipient::query()
            ->where('customer_id', $booking->customer_id)
            ->whereNotNull('sent_at')
            ->whereNull('booked_at')
            ->latest('sent_at')
            ->first();

        $campaignRecipient?->update([
            'booking_id' => $booking->id,
            'booked_at' => now(),
        ]);
    }

    private function sendConfirmation(Booking $booking): void
    {
        $target = $this->whatsAppTarget($booking);
        if (! $target) {
            return;
        }

        [$conversation] = $target;
        $message = sprintf(
            'Baik Bunda, booking %s sudah dikonfirmasi untuk %s pukul %s. Mohon hadir 10 menit sebelum jadwal ya Bunda.',
            $booking->service?->name ?: 'treatment Gayatri',
            $booking->booking_date?->format('d M Y'),
            substr((string) $booking->start_time, 0, 5),
        );

        SendWhatsAppMessageJob::dispatch($conversation->id, null, 'system', $message, $conversation->whatsappSession?->session_name);
    }

    private function sendRescheduleNotice(Booking $booking, ?string $oldDate, ?string $oldTime): void
    {
        $target = $this->whatsAppTarget($booking);
        if (! $target) {
            return;
        }

        [$conversation] = $target;
        $message = sprintf(
            'Baik Bunda, jadwal booking %s sudah kami ubah dari %s pukul %s menjadi %s pukul %s. Mohon hadir 10 menit sebelum jadwal baru ya Bunda.',
            $booking->service?->name ?: 'treatment Gayatri',
            $oldDate ? Carbon::parse($oldDate)->format('d M Y') : '-',
            $oldTime ?: '-',
            $booking->booking_date?->format('d M Y'),
            substr((string) $booking->start_time, 0, 5),
        );

        SendWhatsAppMessageJob::dispatch($conversation->id, null, 'system', $message, $conversation->whatsappSession?->session_name);
    }

    private function sendCancellationNotice(Booking $booking): void
    {
        $target = $this->whatsAppTarget($booking);
        if (! $target) {
            return;
        }

        [$conversation] = $target;
        $message = sprintf(
            'Baik Bunda, booking %s untuk %s pukul %s sudah dibatalkan. Jika Bunda ingin membuat jadwal baru, silakan chat kami kembali kapan saja ya.',
            $booking->service?->name ?: 'treatment Gayatri',
            $booking->booking_date?->format('d M Y'),
            substr((string) $booking->start_time, 0, 5),
        );

        SendWhatsAppMessageJob::dispatch($conversation->id, null, 'system', $message, $conversation->whatsappSession?->session_name);
    }

    private function whatsAppTarget(Booking $booking): ?array
    {
        $booking->loadMissing(['customer', 'service', 'therapist', 'conversation.whatsappSession']);
        $conversation = $booking->conversation ?: Conversation::query()
            ->with('whatsappSession')
            ->where('customer_id', $booking->customer_id)
            ->latest('last_message_at')
            ->first();

        if (! $conversation || ! $booking->customer?->whatsapp_number) {
            return null;
        }

        return [$conversation];
    }
}
