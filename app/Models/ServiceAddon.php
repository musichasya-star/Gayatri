<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'addon_service_id',
        'duration_minutes',
        'price_adjustment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function addonService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'addon_service_id');
    }
}
