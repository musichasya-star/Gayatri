<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayGreeting extends Model
{
    public const STATUSES = [
        'queued' => 'Dalam antrean',
        'sending' => 'Sedang dikirim',
        'sent' => 'Terkirim',
        'failed' => 'Gagal',
        'unknown' => 'Perlu diperiksa',
        'skipped' => 'Dilewati',
    ];

    protected $fillable = [
        'customer_id', 'birthday_type', 'year', 'birthday_on', 'scheduled_at', 'status',
        'session_name', 'chat_id', 'message', 'wa_message_id', 'message_id',
        'attempts', 'started_at', 'sent_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'birthday_on' => 'date', 'scheduled_at' => 'datetime', 'started_at' => 'datetime',
            'sent_at' => 'datetime', 'year' => 'integer', 'attempts' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messageRecord(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
