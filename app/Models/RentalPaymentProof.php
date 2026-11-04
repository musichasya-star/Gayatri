<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalPaymentProof extends Model
{
    public const STATUSES = ['pending' => 'Menunggu verifikasi', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    protected $guarded = ['id'];

    protected $hidden = ['file_path', 'file_hash'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'payment_date' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
