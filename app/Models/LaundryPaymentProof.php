<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryPaymentProof extends Model
{
    public const STATUSES = ['pending' => 'Menunggu pemeriksaan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    protected $guarded = ['id'];

    protected $hidden = ['input_hash', 'file_path'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'reviewed_at' => 'datetime', 'revision' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function publicRequest(): BelongsTo
    {
        return $this->belongsTo(LaundryPublicRequest::class, 'laundry_public_request_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(LaundryPayment::class, 'laundry_payment_id');
    }
}
