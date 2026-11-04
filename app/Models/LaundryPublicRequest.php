<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryPublicRequest extends Model
{
    public const STATUSES = ['pending' => 'Menunggu konfirmasi admin', 'confirmed' => 'Kontak terverifikasi', 'received' => 'Barang sudah diterima outlet', 'rejected' => 'Permintaan ditolak'];

    protected $guarded = ['id'];

    protected $hidden = ['portal_token_hash', 'portal_token_secret', 'input_hash'];

    protected function casts(): array
    {
        return ['delivery_required' => 'boolean', 'items' => 'array', 'estimate' => 'array', 'outlet_snapshot' => 'array', 'preferred_at' => 'datetime', 'verified_at' => 'datetime', 'portal_expires_at' => 'datetime', 'portal_revoked_at' => 'datetime', 'portal_version' => 'integer', 'revision' => 'integer', 'is_demo' => 'boolean', 'portal_token_secret' => 'encrypted'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pickupTask(): BelongsTo
    {
        return $this->belongsTo(LaundryTransportTask::class, 'pickup_task_id');
    }
}
