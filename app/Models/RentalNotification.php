<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalNotification extends Model
{
    public const STATUSES = ['queued' => 'Dalam antrean', 'sending' => 'Sedang dikirim', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'unknown' => 'Perlu diperiksa', 'skipped' => 'Dilewati'];

    protected $fillable = ['rental_order_id', 'message_id', 'kind', 'event_key', 'status', 'context', 'content', 'chat_id', 'session_name', 'wa_message_id', 'attempts', 'expires_at', 'started_at', 'sent_at', 'failure_reason'];

    protected function casts(): array
    {
        return ['context' => 'array', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }
}
