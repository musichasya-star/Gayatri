<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalCustomerRequest extends Model
{
    public const KINDS = ['cancellation' => 'Pembatalan pesanan', 'refund' => 'Pengembalian dana'];

    public const STATUSES = ['pending' => 'Menunggu keputusan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'settled' => 'Refund sudah dicatat'];

    protected $fillable = ['rental_order_id', 'entry_key', 'kind', 'reason', 'estimate', 'status', 'approved_amount', 'reviewed_by', 'reviewed_at', 'review_notes', 'rental_payment_id', 'settled_by', 'settled_at', 'refund_date', 'refund_file_path', 'refund_file_mime', 'refund_file_hash'];

    protected $hidden = ['refund_file_path', 'refund_file_hash'];

    protected function casts(): array
    {
        return ['estimate' => 'array', 'approved_amount' => 'integer', 'reviewed_at' => 'datetime', 'settled_at' => 'datetime', 'refund_date' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'rental_order_id');
    }
}
