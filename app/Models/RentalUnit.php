<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalUnit extends Model
{
    public const STATUSES = ['ready' => 'Siap disewa', 'rented' => 'Sedang disewa', 'inspection' => 'Menunggu pemeriksaan', 'cleaning' => 'Dibersihkan', 'maintenance' => 'Diperbaiki', 'retired' => 'Diarsipkan'];

    protected $fillable = ['rental_product_id', 'code', 'serial_number', 'status', 'condition_notes', 'last_inspected_at', 'next_maintenance_at', 'care_version', 'is_demo'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'last_inspected_at' => 'datetime', 'next_maintenance_at' => 'date', 'care_version' => 'integer'];
    }

    public function scopeForReport($query, bool $includeDemo = false)
    {
        return $query->when(! $includeDemo, fn ($q) => $q->where('is_demo', false));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RentalProduct::class, 'rental_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    public function careRecords(): HasMany
    {
        return $this->hasMany(RentalCareRecord::class);
    }

    public function maintenanceDue(): bool
    {
        return $this->next_maintenance_at && $this->next_maintenance_at->lte(today());
    }
}
