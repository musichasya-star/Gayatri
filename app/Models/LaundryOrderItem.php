<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tariff_snapshot' => 'array', 'received_pieces' => 'integer', 'actual_pieces' => 'integer', 'estimated_milli' => 'integer', 'actual_milli' => 'integer', 'estimated_billable_milli' => 'integer', 'actual_billable_milli' => 'integer', 'estimated_amount' => 'integer', 'actual_amount' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }
}
