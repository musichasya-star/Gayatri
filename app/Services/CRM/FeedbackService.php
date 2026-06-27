<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Followup;
use App\Models\Message;
use Illuminate\Support\Str;

class FeedbackService
{
    public function requestForBooking(Booking $booking): ?Feedback
    {
        $booking->loadMissing(['customer', 'conversation.whatsappSession', 'service']);

        if (! $booking->customer?->whatsapp_number) {
            return null;
        }

        $feedback = Feedback::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'customer_id' => $booking->customer_id,
                'status' => 'requested',
            ]
        );

        if ($feedback->wasRecentlyCreated) {
            $conversation = $this->conversationForBooking($booking);
            $message = 'Terima kasih Bunda sudah berkunjung dan treatment di Gayatri. Jika ada saran atau masukan, silakan hubungi kami melalui chat ini ya. Bunda juga boleh beri rating pengalaman hari ini dari 1-5.';

            SendWhatsAppMessageJob::dispatch($conversation->id, null, 'system', $message, $conversation->whatsappSession?->session_name);
        }

        return $feedback;
    }

    public function captureIncoming(Message $message): ?Feedback
    {
        if (! $message->customer_id || trim((string) $message->content) === '') {
            return null;
        }

        $feedback = Feedback::query()
            ->where('customer_id', $message->customer_id)
            ->where('status', 'requested')
            ->latest()
            ->first();

        if (! $feedback) {
            return null;
        }

        $rating = $this->extractRating((string) $message->content);
        if (! $rating) {
            return null;
        }

        $status = $this->needsEscalation($rating, (string) $message->content) ? 'escalated' : 'received';
        $feedback->update([
            'rating' => $rating,
            'message' => trim((string) $message->content),
            'status' => $status,
        ]);

        if ($status === 'escalated') {
            $this->createComplaintFollowup($feedback, $message);
        }

        return $feedback;
    }

    private function conversationForBooking(Booking $booking): Conversation
    {
        if ($booking->conversation) {
            return $booking->conversation;
        }

        return Conversation::firstOrCreate(
            ['wa_chat_id' => $booking->customer->whatsapp_number.'@c.us'],
            ['customer_id' => $booking->customer_id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]
        );
    }

    private function extractRating(string $content): ?int
    {
        if (preg_match('/\b([1-5])\b/', $content, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function needsEscalation(int $rating, string $content): bool
    {
        return $rating <= 3 || Str::contains(Str::lower($content), [
            'komplain', 'kecewa', 'kurang puas', 'tidak puas', 'marah', 'buruk', 'lama', 'kasar', 'sakit',
        ]);
    }

    private function createComplaintFollowup(Feedback $feedback, Message $message): void
    {
        Followup::firstOrCreate(
            ['customer_id' => $feedback->customer_id, 'title' => 'Follow-up komplain feedback #'.$feedback->id],
            [
                'conversation_id' => $message->conversation_id,
                'notes' => 'Rating '.$feedback->rating.'. Pesan: '.$feedback->message,
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->addHours(2),
            ]
        );
    }
}
