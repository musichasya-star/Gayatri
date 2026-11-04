<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class Conversation extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Conversation $conversation) {
            $controlStatus = ['human_handled', 'closed', 'escalated'];
            if ($conversation->isDirty(['ai_enabled', 'assigned_user_id', 'customer_id', 'wa_chat_id', 'whatsapp_session_id', 'closed_at', 'ai_resumed_at', 'ai_reply_after_message_id'])
                || ($conversation->isDirty('status') && (in_array($conversation->status, $controlStatus, true)
                    || in_array($conversation->getOriginal('status'), $controlStatus, true)))) {
                $conversation->ai_control_revision = DB::raw('ai_control_revision + 1');
            }
        });
        static::updated(function (Conversation $conversation) {
            if (($conversation->getAttributes()['ai_control_revision'] ?? null) instanceof Expression) {
                $conversation->ai_control_revision = (int) static::whereKey($conversation->id)->value('ai_control_revision');
            }
        });
    }

    protected $fillable = [
        'customer_id',
        'whatsapp_session_id',
        'assigned_user_id',
        'wa_chat_id',
        'channel',
        'status',
        'ai_enabled',
        'unread_count',
        'last_message_at',
        'last_incoming_at',
        'closed_at',
        'internal_note',
        'ai_needs_attention', 'ai_handoff_reason',
        'ai_reply_after_message_id', 'ai_resumed_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'ai_needs_attention' => 'boolean',
            'ai_reply_after_message_id' => 'integer', 'ai_resumed_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_incoming_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsAppSession::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
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

    public function aiLogs(): HasMany
    {
        return $this->hasMany(AiLog::class);
    }
}
