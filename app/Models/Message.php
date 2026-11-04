<?php

namespace App\Models;

use App\Services\CRM\RentalMessagingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'user_id',
        'wa_message_id',
        'direction',
        'sender_type',
        'message_type',
        'content',
        'payload',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'failed_reason',
        'retry_count',
        'outbound_key', 'delivery_status', 'send_provider', 'send_session', 'send_chat_id', 'dispatch_token',
        'queued_at', 'sending_started_at', 'send_attempts', 'delivery_revision', 'failure_code', 'ai_reply_context', 'ai_reply_dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'ai_reply_context' => 'array',
            'ai_reply_dispatched_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'retry_count' => 'integer',
            'queued_at' => 'datetime', 'sending_started_at' => 'datetime', 'send_attempts' => 'integer', 'delivery_revision' => 'integer',
        ];
    }

    protected $hidden = ['dispatch_token', 'outbound_key', 'ai_reply_context'];

    public function setContentAttribute($value): void
    {
        $this->attributes['content'] = is_string($value) ? RentalMessagingService::redact($value) : $value;
    }

    public function setPayloadAttribute($value): void
    {
        $this->attributes['payload'] = $value === null ? null : $this->asJson(RentalMessagingService::redactPayload($value));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiLogs(): HasMany
    {
        return $this->hasMany(AiLog::class);
    }
}
