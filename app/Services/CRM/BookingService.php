<?php

namespace App\Services\CRM;

use App\Jobs\SendHomeVisitAssignmentJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Promo;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\Therapist;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\PaymentStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private readonly ReminderService $reminderService,
        private readonly FeedbackService $feedbackService,
    ) {}

    public function create(array $data, ?int $userId): Booking
    {
        return DB::transaction(fn () => $this->createBooking($data, $userId));
    }

    private function createBooking(array $data, ?int $userId): Booking
    {
        $service = Service::where('is_active', true)->find($data['service_id']);

        if (! $service) {
            throw new InvalidArgumentException('Layanan tidak aktif atau tidak ditemukan.');
        }
        if ($service->requiresManualBooking()) {
            throw new InvalidArgumentException('Pilihan layanan/paket ini memerlukan konfirmasi admin mengenai durasi, harga, atau jadwal kunjungan. Pilih melalui katalog dan hubungi admin.');
        }

        $bookingDate = Carbon::parse($data['booking_date'])->startOfDay();
        if ($bookingDate->isPast() && ! $bookingDate->isToday()) {
            throw new InvalidArgumentException('Tanggal booking tidak boleh di masa lalu.');
        }

        $selectedAddOns = $this->selectedAddOns($service->id, $data['addons'] ?? []);
        $extraDuration = (int) $selectedAddOns->sum('duration_minutes');
        $startTime = Carbon::createFromFormat('H:i', substr($data['start_time'], 0, 5));
        $slotEndTime = (clone $startTime)->addMinutes((int) $service->duration_minutes);
        $endTime = (clone $startTime)->addMinutes((int) $service->duration_minutes + $extraDuration);

        $this->validateOperatingHours($startTime, $endTime);

        $slot = $this->resolveSlot($data, $service->id, $bookingDate->toDateString(), $startTime->format('H:i:s'), $slotEndTime->format('H:i:s'));
        $visit = $this->visitAttributes($data, $service, $slot, $bookingDate, $startTime, $endTime);
        if ($visit['service_mode'] === 'clinic') {
            $visit['service_price_snapshot'] = $service->price;
        }
        $promo = $this->validPromo($data['promo_id'] ?? null);

        $booking = Booking::create(array_merge([
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
            'finance_managed' => ($data['payment_status'] ?? PaymentStatus::UNPAID) === PaymentStatus::UNPAID,
            'finance_opened_at' => ($data['payment_status'] ?? PaymentStatus::UNPAID) === PaymentStatus::UNPAID ? now() : null,
            'source' => $data['source'] ?? 'manual',
            'promo_discount' => $this->discountAmount($promo, (float) ($visit['service_price_snapshot'] ?? $service->price)),
            'notes' => $data['notes'] ?? null,
        ], $visit));
        $this->syncBookingAddOns($booking, $selectedAddOns);
        app(TherapistFeeService::class)->sync($booking, auth()->user(), request());

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
        $updated = DB::transaction(fn () => $this->updateBooking($booking, $data));

        return $booking->setRawAttributes($updated->getAttributes(), true)->unsetRelations();
    }

    private function updateBooking(Booking $booking, array $data): Booking
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $finance = app(BookingFinance::class);
        $frozen = (int) $booking->legacy_credit_cents > 0 || $booking->payments()->whereNull('voided_at')->exists();
        $oldFingerprint = $frozen ? $finance->fingerprint($booking) : null;
        if ($booking->finance_managed && $data['payment_status'] !== $booking->payment_status) {
            throw ValidationException::withMessages(['payment_status' => 'Status pembayaran mengikuti jurnal. Catat pembayaran/refund/koreksi melalui menu transaksi.']);
        }
        if ($booking->public_request_data && ! $booking->contact_verified_at && in_array($data['status'], ['confirmed', 'completed'], true)) {
            throw ValidationException::withMessages(['booking' => 'Verifikasi kontak permintaan publik sebelum konfirmasi atau penyelesaian.']);
        }
        if (in_array($data['status'], ['cancelled', 'cancelled_by_user', 'no_show'], true) && $booking->finance_managed && $finance->figures($booking)['net'] > 0) {
            throw ValidationException::withMessages(['booking' => 'Selesaikan pencatatan refund yang benar-benar dilakukan sebelum membatalkan booking berbayar.']);
        }
        $service = Service::where('is_active', true)->find($data['service_id']);
        if ($service?->requiresManualBooking()) {
            throw new InvalidArgumentException('Pilihan layanan/paket ini memerlukan konfirmasi admin sebelum dijadwalkan.');
        }

        if (! $service) {
            throw new InvalidArgumentException('Layanan tidak aktif atau tidak ditemukan.');
        }

        $bookingDate = Carbon::parse($data['booking_date'])->startOfDay();
        if ($bookingDate->isPast() && ! $bookingDate->isToday()) {
            throw new InvalidArgumentException('Tanggal booking tidak boleh di masa lalu.');
        }

        $selectedAddOns = $this->selectedAddOns($service->id, $data['addons'] ?? []);
        $extraDuration = (int) $selectedAddOns->sum('duration_minutes');
        $startTime = Carbon::createFromFormat('H:i', substr($data['start_time'], 0, 5));
        $slotEndTime = (clone $startTime)->addMinutes((int) $service->duration_minutes);
        $endTime = (clone $startTime)->addMinutes((int) $service->duration_minutes + $extraDuration);

        $this->validateOperatingHours($startTime, $endTime);

        $oldPromoId = $booking->promo_id;
        $oldSlot = $booking->availabilitySlot;
        $promo = $this->validPromo($data['promo_id'] ?? null, $oldPromoId);
        $slot = $this->resolveSlot($data, $service->id, $bookingDate->toDateString(), $startTime->format('H:i:s'), $slotEndTime->format('H:i:s'), $booking);
        $visit = $this->visitAttributes($data, $service, $slot, $bookingDate, $startTime, $endTime, $booking);
        if ($visit['service_mode'] === 'clinic') {
            $visit['service_price_snapshot'] = $booking->service_mode === 'clinic' && $booking->service_id === $service->id
                ? $booking->service_price_snapshot : $service->price;
        }
        $assignmentChanged = (int) $booking->therapist_id !== (int) ($visit['therapist_id'] ?? 0) || $booking->visit_address !== ($visit['visit_address'] ?? null) || $booking->visit_landmark !== ($visit['visit_landmark'] ?? null) || $booking->visit_latitude != ($visit['visit_latitude'] ?? null) || $booking->visit_longitude != ($visit['visit_longitude'] ?? null) || (float) $booking->travel_fee !== (float) $visit['travel_fee'];

        $oldStatus = $booking->status;
        $oldServiceMode = $booking->service_mode;
        $oldVisitTherapist = $booking->service_mode === 'home' ? $booking->therapist_id : null;
        $oldBookingDate = $booking->booking_date?->toDateString();
        $oldStartTime = substr((string) $booking->start_time, 0, 5);
        $wasConfirmed = $oldStatus === BookingStatus::CONFIRMED;
        $wasCompleted = $oldStatus === BookingStatus::COMPLETED;

        $booking->update(array_merge([
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
            'promo_discount' => $booking->service_mode === 'home' && $visit['service_mode'] === 'home' && $oldPromoId === $promo?->id ? $booking->promo_discount : $this->discountAmount($promo, (float) ($visit['service_price_snapshot'] ?? $service->price)),
            'notes' => $data['notes'] ?? null,
        ], $visit));
        $this->syncBookingAddOns($booking, $selectedAddOns);

        if ($frozen && $oldFingerprint !== $finance->fingerprint($booking->refresh())) {
            throw ValidationException::withMessages(['booking' => 'Pelanggan dan rincian tagihan dikunci setelah ada saldo awal/pembayaran. Tinjau refund atau koreksi pencatatan terlebih dahulu.']);
        }
        $booking->increment('finance_revision');
        $this->syncPromoUsage($oldPromoId, $promo?->id);
        app(TherapistFeeService::class)->sync($booking, auth()->user(), request());
        $booking->unsetRelation('therapist')->unsetRelation('customer')->unsetRelation('service')->unsetRelation('availabilitySlot');
        $this->syncSlotUsage($oldSlot, $slot, $booking);
        if ($wasConfirmed && $oldVisitTherapist && ($booking->service_mode !== 'home' || (int) $booking->therapist_id !== (int) $oldVisitTherapist)) {
            SendHomeVisitAssignmentJob::dispatch($booking->id, $booking->homeVisitRevision(), $oldVisitTherapist, true)->afterCommit();
        }

        if (! $wasConfirmed && $booking->status === BookingStatus::CONFIRMED) {
            $this->reminderService->syncForConfirmedBooking($booking);
            $this->sendConfirmation($booking);
        }

        $scheduleChanged = $oldBookingDate !== $booking->booking_date?->toDateString() || $oldStartTime !== substr((string) $booking->start_time, 0, 5);
        $sentConfirmationForStatusChange = ! $wasConfirmed && $booking->status === BookingStatus::CONFIRMED;
        if ($booking->service_mode === 'home' && $booking->status === BookingStatus::CONFIRMED && $assignmentChanged && ! $scheduleChanged && ! $sentConfirmationForStatusChange) {
            $this->sendConfirmation($booking);
        }
        if ($oldServiceMode === 'home' && $booking->service_mode === 'clinic' && $booking->status === BookingStatus::CONFIRMED && ! $scheduleChanged && ! $sentConfirmationForStatusChange) {
            $this->sendConfirmation($booking);
        }
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
        $updated = DB::transaction(fn () => $this->cancelBooking($booking, $status, $note));

        return $booking->setRawAttributes($updated->getAttributes(), true)->unsetRelations();
    }

    private function cancelBooking(Booking $booking, string $status, ?string $note): Booking
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($booking->finance_managed && app(BookingFinance::class)->figures($booking)['net'] > 0) {
            throw ValidationException::withMessages(['booking' => 'Booking berbayar memerlukan pencatatan refund sebelum dibatalkan.']);
        }
        if (! in_array($status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER], true)) {
            throw new InvalidArgumentException('Status pembatalan tidak valid.');
        }

        $oldSlot = $booking->availabilitySlot;
        $booking->update([
            'status' => $status,
            'notes' => trim(($booking->notes ? $booking->notes."\n" : '').($note ?: 'Booking dibatalkan.')),
        ]);
        app(TherapistFeeService::class)->sync($booking, auth()->user(), request());

        $this->releaseSlot($oldSlot);
        $this->reminderService->cancelForBooking($booking);
        $this->sendCancellationNotice($booking->refresh());

        return $booking->refresh();
    }

    public function delete(Booking $booking): void
    {
        DB::transaction(fn () => $this->deleteBooking($booking));
    }

    private function deleteBooking(Booking $booking): void
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($booking->payments()->exists() || (int) $booking->legacy_credit_cents > 0 || in_array($booking->payment_status, ['paid', 'partial', 'refunded'], true)) {
            throw ValidationException::withMessages(['booking' => 'Booking memiliki riwayat pembayaran. Batalkan dan pertahankan jejak transaksi.']);
        }
        if ($booking->therapistFee()->exists()) {
            throw ValidationException::withMessages(['booking' => 'Booking memiliki riwayat fee terapis. Batalkan/koreksi status agar jejak perhitungan tetap tersimpan.']);
        }
        $slot = $booking->availabilitySlot;
        $shouldReleaseSlot = ! in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW], true);

        $booking->delete();

        if ($shouldReleaseSlot) {
            $this->releaseSlot($slot);
        }
    }

    public function hasConflict(int $therapistId, string $bookingDate, string $startTime, string $endTime, ?int $ignoreBookingId = null, ?array $quote = null): bool
    {
        $window = app(HomeService::class)->busyWindow($bookingDate, $startTime, $endTime, $quote);

        return Booking::query()
            ->where('therapist_id', $therapistId)
            ->whereBetween('booking_date', [Carbon::parse($window['busy_from'])->subDay()->startOfDay(), Carbon::parse($window['busy_until'])->addDay()->endOfDay()])
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW])
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->get()->contains(function (Booking $other) use ($window) {
                $from = $other->busy_from ?? Carbon::parse($other->booking_date->toDateString().' '.$other->start_time);
                $until = $other->busy_until ?? Carbon::parse($other->booking_date->toDateString().' '.$other->end_time);

                return $from < $window['busy_until'] && $until > $window['busy_from'];
            });
    }

    private function visitAttributes(array $data, Service $service, ?AvailabilitySlot $slot, Carbon $date, Carbon $start, Carbon $end, ?Booking $booking = null): array
    {
        $home = ($data['service_mode'] ?? $booking?->service_mode ?? 'clinic') === 'home';
        $therapistId = $data['therapist_id'] ?? ($home ? $slot?->therapist_id : null);
        if ($home && $slot?->therapist_id && (int) $slot->therapist_id !== (int) $therapistId) {
            throw new InvalidArgumentException('Terapis harus sesuai dengan slot Home Service yang dipilih.');
        }
        if ($therapistId) {
            Therapist::whereKey($therapistId)->lockForUpdate()->first();
        }
        $visit = app(HomeService::class)->attributes(array_merge($data, ['therapist_id' => $therapistId]), $service, $booking);
        $quote = $visit['home_service_quote'];
        if ($therapistId && ! in_array($data['status'] ?? '', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW], true) && $this->hasConflict((int) $therapistId, $date->toDateString(), $start->format('H:i:s'), $end->format('H:i:s'), $booking?->id, $quote)) {
            throw new InvalidArgumentException('Jadwal terapis bentrok dengan booking lain, termasuk waktu perjalanan Home Service.');
        }

        return array_merge($visit, ['therapist_id' => $therapistId], app(HomeService::class)->busyWindow($date->toDateString(), $start->format('H:i:s'), $end->format('H:i:s'), $quote));
    }

    private function bookingCode(): string
    {
        do {
            $code = 'BK-GAY-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    private function selectedAddOns(int $serviceId, array $addonIds)
    {
        $ids = collect($addonIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $addons = ServiceAddon::query()
            ->with('addonService')
            ->withinServiceGroup()
            ->where('service_id', $serviceId)
            ->where('is_active', true)
            ->where('requires_confirmation', false)
            ->whereIn('id', $ids)
            ->get();

        if ($addons->count() !== $ids->count()) {
            throw new InvalidArgumentException('Addon layanan tidak sesuai dengan layanan utama.');
        }

        return $addons;
    }

    private function syncBookingAddOns(Booking $booking, $addons): void
    {
        if ($booking->service_mode === 'home' && $booking->addOns()->pluck('service_addon_id')->sort()->values()->all() === $addons->pluck('id')->sort()->values()->all()) {
            return;
        }
        $booking->unsetRelation('addOns');
        $booking->addOns()->delete();

        foreach ($addons as $addon) {
            $booking->addOns()->create([
                'service_addon_id' => $addon->id,
                'addon_service_id' => $addon->addon_service_id,
                'name' => $addon->displayName() ?: 'Addon layanan',
                'duration_minutes' => (int) $addon->duration_minutes,
                'price' => $booking->service_mode === 'home' ? (float) ($booking->home_service_quote['addon_prices'][$addon->id] ?? $addon->price_adjustment) : (float) $addon->price_adjustment,
            ]);
        }
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
            ? AvailabilitySlot::whereKey((int) $data['availability_slot_id'])->lockForUpdate()->first()
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
                ->lockForUpdate()
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
        if ($booking->service_mode === 'home') {
            $this->sendHomeVisitNotice($booking);

            return;
        }
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
        if ($booking->service_mode === 'home') {
            $this->sendHomeVisitNotice($booking);

            return;
        }
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
        if ($booking->service_mode === 'home' && $booking->therapist_id) {
            SendHomeVisitAssignmentJob::dispatch($booking->id, $booking->homeVisitRevision())->afterCommit();
        }
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

    private function sendHomeVisitNotice(Booking $booking): void
    {
        $booking->loadMissing(['customer', 'service', 'therapist']);
        $conversation = $booking->conversation ?: Conversation::where('customer_id', $booking->customer_id)->whereNotNull('wa_chat_id')->where('wa_chat_id', '!=', '')->latest('last_message_at')->first();
        if (! $conversation && $booking->customer?->normalized_whatsapp_number) {
            $conversation = Conversation::create(['customer_id' => $booking->customer_id, 'wa_chat_id' => $booking->customer->normalized_whatsapp_number.'@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
        }
        if ($conversation) {
            $message = 'Bunda, booking Home Service '.$booking->booking_code.' '.($booking->status === BookingStatus::CONFIRMED ? 'dikonfirmasi' : 'diperbarui (menunggu konfirmasi)').' untuk '.$booking->service?->name.' pada '.$booking->booking_date->format('d M Y').' pukul '.substr($booking->start_time, 0, 5).'. Terapis: '.($booking->therapist?->name ?: 'menunggu penugasan').'. Alamat: '.$booking->visit_address.'. Patokan: '.($booking->visit_landmark ?: '-').'. Ongkos: Rp '.number_format((float) $booking->travel_fee, 0, ',', '.').'. Total: Rp '.number_format($booking->totalPrice(), 0, ',', '.').'. '.($booking->status === BookingStatus::CONFIRMED ? 'Tim kami akan datang ke lokasi Bunda. ' : 'Jadwal akan dikonfirmasi admin terlebih dahulu. ').$booking->navigationUrl();
            SendWhatsAppMessageJob::dispatch($conversation->id, null, 'system', $message)->afterCommit();
        }
        if ($booking->status === BookingStatus::CONFIRMED && $booking->therapist_id) {
            SendHomeVisitAssignmentJob::dispatch($booking->id, $booking->homeVisitRevision())->afterCommit();
        }
    }
}
