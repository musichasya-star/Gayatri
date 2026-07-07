<?php

namespace App\Services\CRM;

use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Reminder;
use App\Services\WhatsApp\WahaService;
use App\Support\BookingStatus;
use App\Support\ReminderStatus;
use App\Support\ReminderType;
use Carbon\Carbon;

class ReminderService
{
    public function syncForConfirmedBooking(Booking $booking): void
    {
        $booking->loadMissing(['customer', 'conversation']);

        if ($booking->status !== BookingStatus::CONFIRMED) {
            return;
        }

        $bookingAt = Carbon::parse($booking->booking_date->toDateString().' '.substr((string) $booking->start_time, 0, 8));

        if ((bool) config('crm.reminder_h1_enabled', true)) {
            $this->upsertReminder($booking, ReminderType::H1, $bookingAt->copy()->subDay());
        }

        if ((bool) config('crm.reminder_h0_enabled', true)) {
            $this->upsertReminder($booking, ReminderType::H0, $bookingAt->copy()->subHours(3));
        }
    }

    public function cancelForBooking(Booking $booking): void
    {
        Reminder::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED, ReminderStatus::FAILED])
            ->update(['status' => ReminderStatus::CANCELLED]);
    }

    public function dispatchDue(): int
    {
        $reminders = Reminder::query()
            ->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED])
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($reminders as $reminder) {
            SendBookingReminderJob::dispatch($reminder->id);
        }

        return $reminders->count();
    }

    public function retry(Reminder $reminder): void
    {
        $reminder->update([
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now(),
            'failed_reason' => null,
        ]);

        (new SendBookingReminderJob($reminder->id))->handle(app(WahaService::class));
    }

    public function cancel(Reminder $reminder): void
    {
        $reminder->update([
            'status' => ReminderStatus::CANCELLED,
        ]);
    }

    public function sendNow(Reminder $reminder): void
    {
        $reminder->update([
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now(),
            'failed_reason' => null,
        ]);

        (new SendBookingReminderJob($reminder->id))->handle(app(WahaService::class), $this);
    }

    public function createManual(array $data): Reminder
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $booking = isset($data['booking_id']) && $data['booking_id'] ? Booking::findOrFail($data['booking_id']) : null;

        $conversationId = $data['conversation_id']
            ?? $booking?->conversation_id
            ?? Conversation::query()->where('customer_id', $customer->id)->latest('last_message_at')->value('id');

        return Reminder::create([
            'booking_id' => $booking?->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversationId,
            'type' => $data['type'],
            'channel' => $data['channel'] ?? 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => Carbon::parse($data['scheduled_at']),
            'message' => $data['message'] ?: null,
            'failed_reason' => null,
            'payload' => ['source' => 'admin-manual'],
        ]);
    }

    public function buildMessage(Reminder $reminder): string
    {
        if (filled($reminder->message)) {
            return (string) $reminder->message;
        }

        $booking = $reminder->booking;

        return match ($reminder->type) {
            ReminderType::H1 => sprintf(
                'Halo Bunda %s, reminder dari Gayatri: besok ada jadwal %s pada %s pukul %s. Mohon hadir 10 menit sebelum jadwal ya.',
                $reminder->customer?->name,
                $booking?->service?->name ?: 'treatment',
                $booking?->booking_date?->format('d M Y'),
                substr((string) $booking?->start_time, 0, 5),
            ),
            ReminderType::H0 => sprintf(
                'Halo Bunda %s, reminder dari Gayatri: hari ini ada jadwal %s pada %s pukul %s. Sampai jumpa ya Bunda.',
                $reminder->customer?->name,
                $booking?->service?->name ?: 'treatment',
                $booking?->booking_date?->format('d M Y'),
                substr((string) $booking?->start_time, 0, 5),
            ),
            ReminderType::PAYMENT => sprintf(
                'Halo Bunda %s, kami mengingatkan masih ada pembayaran untuk booking %s di Gayatri. Jika perlu bantuan, balas chat ini ya Bunda.',
                $reminder->customer?->name,
                $booking?->booking_code ?: '-',
            ),
            ReminderType::RESCHEDULE => sprintf(
                'Halo Bunda %s, kami mengingatkan jadwal %s di Gayatri sedang menunggu konfirmasi reschedule. Mohon kabari pilihan jam pengganti ya.',
                $reminder->customer?->name,
                $booking?->service?->name ?: 'treatment',
            ),
            ReminderType::FOLLOWUP => sprintf(
                'Halo Bunda %s, semoga treatment sebelumnya nyaman ya. Jika Bunda ingin lanjut treatment berikutnya, kami siap bantu jadwalkan.',
                $reminder->customer?->name,
            ),
            ReminderType::PACKAGE_LOW => sprintf(
                'Halo Bunda %s, kami ingin mengingatkan paket treatment Bunda di Gayatri hampir habis. Bila ingin lanjut, kami bisa bantu cek jadwal dan promo aktif.',
                $reminder->customer?->name,
            ),
            default => sprintf(
                'Halo Bunda %s, ini reminder dari Gayatri terkait layanan dan jadwal Bunda. Jika ada pertanyaan, balas chat ini ya.',
                $reminder->customer?->name,
            ),
        };
    }

    private function upsertReminder(Booking $booking, string $type, Carbon $scheduledAt): void
    {
        if ($scheduledAt->isPast()) {
            $scheduledAt = now();
        }

        Reminder::updateOrCreate(
            ['booking_id' => $booking->id, 'type' => $type],
            [
                'customer_id' => $booking->customer_id,
                'conversation_id' => $booking->conversation_id,
                'channel' => 'whatsapp',
                'status' => ReminderStatus::SCHEDULED,
                'scheduled_at' => $scheduledAt,
                'message' => null,
                'sent_at' => null,
                'failed_reason' => null,
                'payload' => ['template' => 'booking-'.$type],
            ]
        );
    }
}
