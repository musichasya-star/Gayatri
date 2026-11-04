<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalPayment extends Model
{
    protected $fillable = ['rental_order_id', 'entry_key', 'kind', 'amount', 'method', 'reference', 'notes', 'created_by', 'party_name'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }
}
