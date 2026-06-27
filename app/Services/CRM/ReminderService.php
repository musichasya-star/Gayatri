<?php

namespace App\Services\CRM;

use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use App\Models\Reminder;
use App\Services\WhatsApp\WahaService;
use App\Support\BookingStatus;
use App\Support\ReminderStatus;
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
            $this->upsertReminder($booking, 'h1', $bookingAt->copy()->subDay());
        }

        if ((bool) config('crm.reminder_h0_enabled', true)) {
            $this->upsertReminder($booking, 'h0', $bookingAt->copy()->subHours(3));
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
                'sent_at' => null,
                'failed_reason' => null,
                'payload' => ['template' => 'booking-'.$type],
            ]
        );
    }
}
