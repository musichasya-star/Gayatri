<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalExtensionRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['new_end_date' => 'date', 'quoted_amount' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }
}
