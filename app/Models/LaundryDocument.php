<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryDocument extends Model
{
    public const TYPES = ['invoice' => 'Invoice Laundry', 'receipt' => 'Kuitansi Pembayaran Laundry'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
