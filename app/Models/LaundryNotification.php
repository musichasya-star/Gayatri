<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryNotification extends Model
{
    public const STATUSES = ['simulated' => 'Simulasi', 'queued' => 'Antrean', 'sending' => 'Sedang dikirim', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'unknown' => 'Perlu diperiksa', 'skipped' => 'Dilewati'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'attempts' => 'integer', 'started_at' => 'datetime', 'sent_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LaundryTransportTask::class, 'laundry_transport_task_id');
    }
}
