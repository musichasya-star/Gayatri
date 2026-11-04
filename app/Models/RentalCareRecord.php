<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalCareRecord extends Model
{
    public const KINDS = ['registered' => 'Pendaftaran unit', 'status' => 'Perubahan kondisi', 'checkout' => 'Pemeriksaan sebelum keluar', 'return_inspection' => 'Pemeriksaan pengembalian', 'inspection' => 'Pemeriksaan berkala', 'cleaning' => 'Pembersihan', 'maintenance' => 'Perbaikan / perawatan'];

    protected $fillable = ['rental_unit_id', 'rental_item_id', 'performed_by', 'entry_key', 'kind', 'status_before', 'status_after', 'checklist', 'notes', 'photo_path', 'cost', 'vendor', 'next_maintenance_at', 'performed_at'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'performed_at' => 'datetime', 'next_maintenance_at' => 'date', 'cost' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'rental_unit_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
