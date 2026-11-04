<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LaundryPayment extends Model
{
    public const METHODS = ['cash' => 'Tunai', 'transfer' => 'Transfer bank'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document(): HasOne
    {
        return $this->hasOne(LaundryDocument::class);
    }
}
