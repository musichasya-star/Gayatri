<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\User;

class RetentionService
{
    public function inactiveCustomers(int $days = 30)
    {
        $threshold = now()->subDays($days);

        return Customer::query()
            ->with(['bookings' => fn ($query) => $query->latest('booking_date'), 'followups'])
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_booking_at')
                    ->orWhere('last_booking_at', '<=', $threshold);
            })
            ->where('status', '!=', 'blacklisted')
            ->orderBy('last_booking_at')
            ->get();
    }

    public function generateFollowups(int $days, User $actor): int
    {
        $count = 0;

        foreach ($this->inactiveCustomers($days) as $customer) {
            $exists = Followup::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'open')
                ->where('title', 'like', 'Retention %')
                ->exists();

            if ($exists) {
                continue;
            }

            Followup::create([
                'customer_id' => $customer->id,
                'conversation_id' => $customer->conversations()->latest('last_message_at')->value('id'),
                'assigned_user_id' => $actor->id,
                'title' => "Retention {$days} Hari",
                'notes' => $this->suggestion($customer, $days),
                'status' => 'open',
                'priority' => $days >= 60 ? 'high' : 'normal',
                'due_at' => now()->addDay(),
            ]);

            $count++;
        }

        return $count;
    }

    public function sendFollowup(Followup $followup): void
    {
        $followup->loadMissing('customer');
        $conversation = $followup->conversation ?: Conversation::query()
            ->where('customer_id', $followup->customer_id)
            ->latest('last_message_at')
            ->first();

        if (! $conversation || ! $followup->customer?->whatsapp_number) {
            throw new \InvalidArgumentException('Conversation WhatsApp customer tidak ditemukan.');
        }

        SendWhatsAppMessageJob::dispatch(
            $conversation->id,
            $followup->assigned_user_id,
            'admin',
            $followup->notes ?: $this->suggestion($followup->customer, 30),
            $conversation->whatsappSession?->session_name,
        );

        $followup->update([
            'status' => 'sent',
        ]);
    }

    public function complete(Followup $followup, ?string $result = null): void
    {
        $notes = trim(($followup->notes ?: '').($result ? "\n\nResult: {$result}" : ''));

        $followup->update([
            'status' => 'completed',
            'notes' => $notes,
            'completed_at' => now(),
        ]);
    }

    private function suggestion(Customer $customer, int $days): string
    {
        return "Halo Bunda {$customer->name}, sudah sekitar {$days} hari sejak treatment terakhir di Gayatri. Kami ingin bantu cek jadwal dan promo treatment lanjutan yang cocok untuk Bunda. Mau kami bantu cek jadwalnya?";
    }
}
