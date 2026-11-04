<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalExtension extends Model
{
    protected $fillable = ['rental_order_id', 'created_by', 'entry_key', 'old_end_date', 'new_end_date', 'schedule_version', 'additional_charge', 'pricing_snapshot', 'notes'];

    protected function casts(): array
    {
        return ['old_end_date' => 'date', 'new_end_date' => 'date', 'additional_charge' => 'decimal:2', 'pricing_snapshot' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }
}
