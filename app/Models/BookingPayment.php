<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPayment extends Model
{
    public const METHODS = ['cash' => 'Tunai', 'transfer' => 'Transfer bank'];

    public const KINDS = ['payment' => 'Pembayaran / DP', 'refund' => 'Pengembalian uang manual'];

    protected $guarded = ['id'];

    protected $hidden = ['input_hash', 'proof_path'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime', 'voided_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
