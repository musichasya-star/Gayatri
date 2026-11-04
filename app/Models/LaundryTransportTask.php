<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryTransportTask extends Model
{
    public const KINDS = ['pickup' => 'Penjemputan', 'delivery' => 'Pengantaran'];

    public const STATUSES = ['planned' => 'Terjadwal', 'in_transit' => 'Dalam perjalanan', 'completed' => 'Selesai', 'failed' => 'Kunjungan gagal', 'cancelled' => 'Dibatalkan'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'quote_snapshot' => 'array', 'history' => 'array', 'revision' => 'integer', 'is_demo' => 'boolean', 'scheduled_start' => 'datetime', 'scheduled_end' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(LaundryCourier::class, 'laundry_courier_id');
    }
}
