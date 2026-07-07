<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\User;

class RetentionService
{
    public function inactiveCustomersQuery(
        int $days = 30,
        ?string $search = null,
        ?string $followupStatus = null,
        ?string $tag = null,
        bool $withActiveFollowupCount = false,
    ) {
        $threshold = now()->subDays($days);

        $query = Customer::query()->select(['id', 'name', 'phone', 'whatsapp_number', 'tags', 'status', 'last_booking_at']);

        if ($withActiveFollowupCount) {
            $query->withCount([
                'followups as active_followups_count' => fn ($followupQuery) => $followupQuery->whereIn('status', ['open', 'sent']),
            ]);
        }

        $query->where(function ($query) use ($threshold) {
                $query->whereNull('last_booking_at')
                    ->orWhere('last_booking_at', '<=', $threshold);
            })
            ->where('status', '!=', 'blacklisted')
            ->orderBy('last_booking_at');

        if ($search !== null && $search !== '') {
            $keyword = '%'.$search.'%';

            $query->where(function ($nested) use ($keyword) {
                $nested->where('name', 'like', $keyword)
                    ->orWhere('phone', 'like', $keyword)
                    ->orWhere('whatsapp_number', 'like', $keyword)
                    ->orWhere('tags', 'like', $keyword);
            });
        }

        if ($followupStatus !== null && $followupStatus !== 'all') {
            switch ($followupStatus) {
                case 'active':
                    $query->whereHas('followups', function ($followupQuery) {
                        $followupQuery->whereIn('status', ['open', 'sent']);
                    });
                    break;
                case 'none':
                    $query->whereDoesntHave('followups');
                    break;
                case 'open':
                case 'sent':
                case 'completed':
                    $query->whereHas('followups', function ($followupQuery) use ($followupStatus) {
                        $followupQuery->where('status', $followupStatus);
                    });
                    break;
            }
        }

        if ($tag !== null && $tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        return $query;
    }

    public function inactiveCustomers(int $days = 30, ?string $search = null, ?string $followupStatus = null, ?string $tag = null)
    {
        return $this->inactiveCustomersQuery($days, $search, $followupStatus, $tag)->get();
    }

    /** @return list<string> */
    public function retentionTagOptions(int $days = 30, ?string $search = null, ?string $followupStatus = null): array
    {
        return $this->inactiveCustomersQuery($days, $search, $followupStatus)
            ->pluck('tags')
            ->filter()
            ->flatMap(fn ($tags) => is_array($tags) ? $tags : [])
            ->map(fn ($tag) => trim((string) $tag))
            ->filter(fn ($tag) => $tag !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function generateFollowups(int $days, User $actor): int
    {
        $count = 0;

        foreach ($this->inactiveCustomers($days) as $customer) {
            if ($this->hasActiveRetentionFollowup($customer->id, $days)) {
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

    public function hasActiveRetentionFollowup(int|string $customerId, int $days): bool
    {
        return Followup::query()
            ->where('customer_id', (int) $customerId)
            ->where('title', 'Retention '.$days.' Hari')
            ->whereIn('status', ['open', 'sent'])
            ->exists();
    }

    public function suggestion(Customer $customer, int $days): string
    {
        return "Halo Bunda {$customer->name}, sudah sekitar {$days} hari sejak treatment terakhir di Gayatri. Kami ingin bantu cek jadwal dan promo treatment lanjutan yang cocok untuk Bunda. Mau kami bantu cek jadwalnya?";
    }

    public function generateQuickFollowupPayload(Customer $customer, int $days, int $assignedUserId): array
    {
        return [
            'customer_id' => $customer->id,
            'title' => "Retention {$days} Hari",
            'notes' => $this->suggestion($customer, $days),
            'status' => 'open',
            'priority' => $days >= 60 ? 'high' : 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'assigned_user_id' => $assignedUserId,
        ];
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

}
