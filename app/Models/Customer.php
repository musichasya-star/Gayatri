<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'whatsapp_number',
        'email',
        'birth_date',
        'baby_name',
        'baby_birth_date',
        'address',
        'city',
        'tags',
        'status',
        'notes',
        'last_interaction_at',
        'last_booking_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'baby_birth_date' => 'date',
            'tags' => 'array',
            'last_interaction_at' => 'datetime',
            'last_booking_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function latestConversation(): HasOne
    {
        return $this->hasOne(Conversation::class)->latestOfMany('last_message_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function aiLogs(): HasMany
    {
        return $this->hasMany(AiLog::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function getWhatsappDisplayAttribute(): string
    {
        $raw = trim((string) $this->whatsapp_number);

        if ($raw === '') {
            return '-';
        }

        $chatId = (string) ($this->latestConversation?->wa_chat_id ?? '');
        if (str_ends_with($raw, '@lid') || str_ends_with($chatId, '@lid')) {
            return 'WhatsApp ID: '.str_replace('@lid', '', $raw ?: $chatId);
        }

        $number = $this->normalized_whatsapp_number;

        return $number ? $this->formatIndonesianPhone($number) : $raw;
    }

    public function getNormalizedWhatsappNumberAttribute(): ?string
    {
        $raw = trim((string) $this->whatsapp_number);
        if ($raw === '' || str_ends_with($raw, '@lid')) {
            return null;
        }

        $number = preg_replace('/\D+/', '', str_replace(['@c.us', '@s.whatsapp.net'], '', $raw));
        if (! is_string($number) || $number === '') {
            return null;
        }

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return preg_match('/^62\d{8,14}$/', $number) ? $number : null;
    }

    private function formatIndonesianPhone(string $number): string
    {
        if (! str_starts_with($number, '62')) {
            return $number;
        }

        $local = substr($number, 2);
        $chunks = strlen($local) > 8
            ? [substr($local, 0, 3), substr($local, 3, 4), substr($local, 7)]
            : str_split($local, 4);

        return '+62 '.implode(' ', array_filter($chunks));
    }
}
